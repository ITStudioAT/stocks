<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Support\Carbon;

class PriceRefreshScheduler
{
    private const ConfigKey = 'price_refresh.schedule';

    public function __construct(
        private DepotHoldingPriceRefreshDispatcher $dispatcher,
        private DepotHoldingPriceRefreshProgress $refreshProgress,
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
        $isUpdating = $this->activeRefreshProgress() !== null;

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

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function activeRefreshProgress(): ?array
    {
        $run = StockPriceRefreshRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->orderByDesc('started_at')
            ->first();

        if (! $run) {
            return null;
        }

        return $this->refreshProgress->getStored((string) $run->id);
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
            $refresh = $this->dispatchWatchlist($recipient, includeIndexes: false)['progress'];
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

        return $this->dispatchWatchlist(includeIndexes: false)['job_count'];
    }

    /**
     * @return array{progress: ?array, job_count: int, total_holdings: int, total_instruments: int}
     */
    public function dispatchWatchlist(?User $recipient = null, bool $includeIndexes = true): array
    {
        $settings = $this->settings();
        $totalHoldings = StockHolding::query()->count();
        $totalInstruments = $totalHoldings + ($includeIndexes ? IndexWatchItem::query()->count() : 0);
        $progress = $this->dispatcher->dispatch($recipient, $includeIndexes);

        $this->storeSettings([
            ...$settings,
            'last_refreshed_at' => now()->toIso8601String(),
            'next_refresh_at' => $this->nextRefreshAt(now(), $settings),
        ]);

        return [
            'progress' => $progress,
            'job_count' => $progress === null ? 0 : 1,
            'total_holdings' => $totalHoldings,
            'total_instruments' => $totalInstruments,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        $isTradingTime = $this->isAnyHoldingWithinTradingTimes($from, $settings);

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return $this->nextTradingRefreshAt($from, $settings)->toIso8601String();
        }

        return $from
            ->copy()
            ->addMinutes($this->currentIntervalMinutes($settings, $isTradingTime))
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
        $isTradingTime = $this->isAnyHoldingWithinTradingTimes($from, $settings);

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return $this->nextTradingRefreshAt($from, $settings)->toIso8601String();
        }

        $lastRefreshedAt = $this->carbon($settings['last_refreshed_at']);

        if ($lastRefreshedAt !== null) {
            return $lastRefreshedAt
                ->copy()
                ->addMinutes($this->currentIntervalMinutes(
                    $settings,
                    $isTradingTime,
                ))
                ->toIso8601String();
        }

        return $settings['next_refresh_at'] ?? $this->nextRefreshAt($from, $settings);
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextTradingRefreshAt(Carbon $from, array $settings): Carbon
    {
        $nextTradingStartsAt = null;

        foreach (StockHolding::query()->whereNotNull('trading_times')->cursor() as $holding) {
            $holdingTradingStartsAt = $this->nextTradingStartsAt((string) $holding->trading_times, $from, $settings);

            if ($holdingTradingStartsAt === null) {
                continue;
            }

            if ($nextTradingStartsAt === null || $holdingTradingStartsAt->lessThan($nextTradingStartsAt)) {
                $nextTradingStartsAt = $holdingTradingStartsAt;
            }
        }

        return $nextTradingStartsAt ?? $from->copy()->addMinutes($settings['trading_interval_minutes']);
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextTradingStartsAt(string $tradingTimes, Carbon $from, array $settings): ?Carbon
    {
        $window = $this->tradingWindow($tradingTimes, $settings);

        if ($window === null) {
            return null;
        }

        $localTime = $from->copy()->setTimezone($window['timezone']);

        for ($daysToAdd = 0; $daysToAdd <= 7; $daysToAdd++) {
            $candidateDay = $localTime->copy()->startOfDay()->addDays($daysToAdd);

            if ($candidateDay->isWeekend()) {
                continue;
            }

            $candidate = $candidateDay->copy()->addMinutes($window['open_minute']);

            if ($candidate->greaterThan($localTime)) {
                return $candidate->setTimezone($from->timezone);
            }
        }

        return null;
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
        $window = $this->tradingWindow($tradingTimes, $settings);

        if ($window === null) {
            return false;
        }

        $localTime = $at->copy()->setTimezone($window['timezone']);

        if ($localTime->isWeekend()) {
            return false;
        }

        $currentMinute = ($localTime->hour * 60) + $localTime->minute;

        return $currentMinute >= $window['open_minute'] && $currentMinute < $window['close_minute'];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     * @return array{timezone: string, open_minute: int, close_minute: int}|null
     */
    private function tradingWindow(string $tradingTimes, array $settings): ?array
    {
        if (! preg_match('/(?<![:\d])(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return null;
        }

        $timezone = preg_match('/\bEurope\/[A-Za-z_]+\b/', $tradingTimes, $timezoneMatches)
            ? $timezoneMatches[0]
            : config('app.timezone', 'UTC');

        return [
            'timezone' => $timezone,
            'open_minute' => (((int) $matches['open_hour'] * 60) + (int) $matches['open_minute']) - $settings['trading_starts_before_minutes'],
            'close_minute' => (((int) $matches['close_hour'] * 60) + (int) $matches['close_minute']) + $settings['trading_ends_after_minutes'],
        ];
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
