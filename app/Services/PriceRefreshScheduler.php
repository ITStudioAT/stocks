<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Support\Carbon;

class PriceRefreshScheduler
{
    private const ConfigKey = 'price_refresh.schedule';

    public function __construct(
        private DepotHoldingPriceRefreshDispatcher $dispatcher,
    ) {}

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);

        $settings = $this->recalculateRefreshTimes($settings);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string, status: string, status_label: string, is_trading_time: bool, current_interval_minutes: int}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $isTradingTime = $this->isAnyHoldingWithinTradingTimes(settings: $settings);
        $isUpdating = $this->hasRunningRefresh();

        return [
            'trading_interval_minutes' => $settings['trading_interval_minutes'],
            'trading_starts_before_minutes' => $settings['trading_starts_before_minutes'],
            'trading_ends_after_minutes' => $settings['trading_ends_after_minutes'],
            'closed_refresh_enabled' => $settings['closed_refresh_enabled'],
            'closed_interval_minutes' => $settings['closed_interval_minutes'],
            'last_refreshed_at' => $settings['last_refreshed_at'],
            'next_refresh_at' => $settings['next_refresh_at'],
            'status' => $isUpdating ? 'updating' : 'waiting',
            'status_label' => $isUpdating ? 'Updating prices' : 'waiting',
            'is_trading_time' => $isTradingTime,
            'current_interval_minutes' => $this->currentIntervalMinutes($settings, $isTradingTime),
        ];
    }

    public function updateSettings(
        int $tradingIntervalMinutes,
        int $tradingStartsBeforeMinutes,
        int $tradingEndsAfterMinutes,
        bool $closedRefreshEnabled,
        int $closedIntervalMinutes,
        ?User $recipient = null,
    ): array {
        $previousSettings = $this->settings();
        $previousIntervalMinutes = $this->currentIntervalMinutes(
            $previousSettings,
            $this->isAnyHoldingWithinTradingTimes(settings: $previousSettings),
        );
        $settings = [
            ...$previousSettings,
            'trading_interval_minutes' => $tradingIntervalMinutes,
            'trading_starts_before_minutes' => $tradingStartsBeforeMinutes,
            'trading_ends_after_minutes' => $tradingEndsAfterMinutes,
            'closed_refresh_enabled' => $closedRefreshEnabled,
            'closed_interval_minutes' => $closedIntervalMinutes,
        ];
        $settings['next_refresh_at'] = $this->nextRefreshAt(now(), $settings);
        $this->storeSettings($settings);

        $isTradingTime = $this->isAnyHoldingWithinTradingTimes(settings: $settings);
        $refresh = null;

        if ($this->shouldDispatchAfterSettingsChange($previousIntervalMinutes, $settings, $isTradingTime)) {
            $refresh = $this->dispatchWatchlist($recipient)['progress'];
        }

        return [
            'settings' => $this->settings(),
            'refresh' => $refresh,
        ];
    }

    public function dispatchDueRefreshes(): int
    {
        $settings = $this->settings();
        $isTradingTime = $this->isAnyHoldingWithinTradingTimes(settings: $settings);

        if ($this->hasRunningRefresh()) {
            return 0;
        }

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return 0;
        }

        $nextRefreshAt = $this->carbon($settings['next_refresh_at']);

        if ($nextRefreshAt !== null && $nextRefreshAt->isFuture()) {
            return 0;
        }

        return $this->dispatchWatchlist()['job_count'];
    }

    /**
     * @return array{progress: ?array, job_count: int, total_holdings: int}
     */
    public function dispatchWatchlist(?User $recipient = null): array
    {
        $settings = $this->settings();
        $totalHoldings = StockHolding::query()->count();
        $progress = $this->dispatcher->dispatch($recipient);

        $this->storeSettings([
            ...$settings,
            'last_refreshed_at' => now()->toIso8601String(),
            'next_refresh_at' => $this->nextRefreshAt(now(), $settings),
        ]);

        return [
            'progress' => $progress,
            'job_count' => $progress === null ? 0 : 1,
            'total_holdings' => $totalHoldings,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        return $from
            ->copy()
            ->addMinutes($this->currentIntervalMinutes($settings, $this->isAnyHoldingWithinTradingTimes($from, $settings)))
            ->toIso8601String();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function currentIntervalMinutes(array $settings, bool $isTradingTime): int
    {
        return $isTradingTime
            ? $settings['trading_interval_minutes']
            : $settings['closed_interval_minutes'];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function shouldDispatchAfterSettingsChange(int $previousIntervalMinutes, array $settings, bool $isTradingTime): bool
    {
        if ($this->hasRunningRefresh()) {
            return false;
        }

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return false;
        }

        return $previousIntervalMinutes !== $this->currentIntervalMinutes($settings, $isTradingTime);
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    private function recalculateRefreshTimes(array $settings): array
    {
        $nextRefreshAt = $this->recalculatedNextRefreshAt(now(), $settings);

        if ($settings['next_refresh_at'] === $nextRefreshAt) {
            return $settings;
        }

        return [
            ...$settings,
            'next_refresh_at' => $nextRefreshAt,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function recalculatedNextRefreshAt(Carbon $from, array $settings): string
    {
        $lastRefreshedAt = $this->carbon($settings['last_refreshed_at']);

        if ($lastRefreshedAt !== null) {
            return $lastRefreshedAt
                ->copy()
                ->addMinutes($this->currentIntervalMinutes(
                    $settings,
                    $this->isAnyHoldingWithinTradingTimes($from, $settings),
                ))
                ->toIso8601String();
        }

        return $settings['next_refresh_at'] ?? $this->nextRefreshAt($from, $settings);
    }

    private function hasRunningRefresh(): bool
    {
        return StockPriceRefreshRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->exists();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}|null  $settings
     */
    private function isAnyHoldingWithinTradingTimes(?Carbon $at = null, ?array $settings = null): bool
    {
        $now = ($at ?? now())->copy();
        $settings ??= $this->settings();

        return StockHolding::query()
            ->whereNotNull('trading_times')
            ->cursor()
            ->contains(fn (StockHolding $holding): bool => $this->isTradingTime((string) $holding->trading_times, $now, $settings));
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function isTradingTime(string $tradingTimes, Carbon $at, array $settings): bool
    {
        if (! preg_match('/(?<open_hour>\d{1,2}):(?<open_minute>\d{2})\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})/i', $tradingTimes, $matches)) {
            return false;
        }

        $timezone = preg_match('/\bEurope\/[A-Za-z_]+\b/', $tradingTimes, $timezoneMatches)
            ? $timezoneMatches[0]
            : config('app.timezone', 'UTC');
        $localTime = $at->copy()->setTimezone($timezone);

        if ($localTime->isWeekend()) {
            return false;
        }

        $currentMinute = ($localTime->hour * 60) + $localTime->minute;
        $openMinute = (((int) $matches['open_hour'] * 60) + (int) $matches['open_minute']) - $settings['trading_starts_before_minutes'];
        $closeMinute = (((int) $matches['close_hour'] * 60) + (int) $matches['close_minute']) + $settings['trading_ends_after_minutes'];

        return $currentMinute >= $openMinute && $currentMinute < $closeMinute;
    }

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    private function defaultSettings(): array
    {
        return [
            'trading_interval_minutes' => 20,
            'trading_starts_before_minutes' => 0,
            'trading_ends_after_minutes' => 0,
            'closed_refresh_enabled' => true,
            'closed_interval_minutes' => 60,
            'last_refreshed_at' => null,
            'next_refresh_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'trading_interval_minutes' => (int) $settings['trading_interval_minutes'],
            'trading_starts_before_minutes' => (int) $settings['trading_starts_before_minutes'],
            'trading_ends_after_minutes' => (int) $settings['trading_ends_after_minutes'],
            'closed_refresh_enabled' => (bool) $settings['closed_refresh_enabled'],
            'closed_interval_minutes' => (int) $settings['closed_interval_minutes'],
            'last_refreshed_at' => is_string($settings['last_refreshed_at']) ? $settings['last_refreshed_at'] : null,
            'next_refresh_at' => is_string($settings['next_refresh_at']) ? $settings['next_refresh_at'] : null,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function storeSettings(array $settings): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );
    }

    private function carbon(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value);
    }
}
