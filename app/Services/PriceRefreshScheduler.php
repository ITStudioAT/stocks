<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use Illuminate\Support\Carbon;

class PriceRefreshScheduler
{
    private const ConfigKey = 'price_refresh.schedule';

    public function __construct(
        private DepotHoldingPriceRefreshDispatcher $dispatcher,
    ) {}

    /**
     * @return array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);

        if ($settings['next_refresh_at'] === null) {
            $settings = [
                ...$settings,
                'next_refresh_at' => $this->nextRefreshAt(now(), $settings),
            ];
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string, status: string, status_label: string, is_trading_time: bool, current_interval_minutes: int}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $isTradingTime = $this->isAnyHoldingWithinTradingTimes();
        $isUpdating = $this->hasRunningRefresh();

        return [
            'trading_interval_minutes' => $settings['trading_interval_minutes'],
            'closed_interval_minutes' => $settings['closed_interval_minutes'],
            'last_refreshed_at' => $settings['last_refreshed_at'],
            'next_refresh_at' => $settings['next_refresh_at'],
            'status' => $isUpdating ? 'updating' : 'waiting',
            'status_label' => $isUpdating ? 'Updating prices' : 'waiting',
            'is_trading_time' => $isTradingTime,
            'current_interval_minutes' => $this->currentIntervalMinutes($settings, $isTradingTime),
        ];
    }

    public function updateIntervals(int $tradingIntervalMinutes, int $closedIntervalMinutes): array
    {
        $settings = $this->settings();
        $settings = [
            ...$settings,
            'trading_interval_minutes' => $tradingIntervalMinutes,
            'closed_interval_minutes' => $closedIntervalMinutes,
        ];
        $settings['next_refresh_at'] = $this->nextRefreshAt(now(), $settings);
        $this->storeSettings($settings);

        return $settings;
    }

    public function dispatchDueRefreshes(): int
    {
        $settings = $this->settings();

        if ($this->hasRunningRefresh()) {
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
    public function dispatchWatchlist(): array
    {
        $settings = $this->settings();
        $totalHoldings = StockHolding::query()->count();
        $progress = $this->dispatcher->dispatch();

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
     * @param  array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        return $from
            ->copy()
            ->addMinutes($this->currentIntervalMinutes($settings, $this->isAnyHoldingWithinTradingTimes($from)))
            ->toIso8601String();
    }

    /**
     * @param  array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function currentIntervalMinutes(array $settings, bool $isTradingTime): int
    {
        return $isTradingTime
            ? $settings['trading_interval_minutes']
            : $settings['closed_interval_minutes'];
    }

    private function hasRunningRefresh(): bool
    {
        return StockPriceRefreshRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->exists();
    }

    private function isAnyHoldingWithinTradingTimes(?Carbon $at = null): bool
    {
        $now = ($at ?? now())->copy();

        return StockHolding::query()
            ->whereNotNull('trading_times')
            ->cursor()
            ->contains(fn (StockHolding $holding): bool => $this->isTradingTime((string) $holding->trading_times, $now));
    }

    private function isTradingTime(string $tradingTimes, Carbon $at): bool
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
        $openMinute = ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'];
        $closeMinute = ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'];

        return $currentMinute >= $openMinute && $currentMinute < $closeMinute;
    }

    /**
     * @return array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    private function defaultSettings(): array
    {
        return [
            'trading_interval_minutes' => 20,
            'closed_interval_minutes' => 60,
            'last_refreshed_at' => null,
            'next_refresh_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'trading_interval_minutes' => (int) $settings['trading_interval_minutes'],
            'closed_interval_minutes' => (int) $settings['closed_interval_minutes'],
            'last_refreshed_at' => is_string($settings['last_refreshed_at']) ? $settings['last_refreshed_at'] : null,
            'next_refresh_at' => is_string($settings['next_refresh_at']) ? $settings['next_refresh_at'] : null,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
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
