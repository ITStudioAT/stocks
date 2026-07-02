<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Support\Carbon;

class IndexPriceRefreshSettings
{
    private const ConfigKey = 'index_price_refresh.schedule';

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
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string, status: string, status_label: string, is_trading_time: bool, current_interval_minutes: int, table_name: string, table_row_count: int, latest_table_update_at: ?string}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $isTradingTime = $this->isAnyIndexWithinTradingTimes(settings: $settings);
        $isUpdating = $this->hasRunningCombinedRefresh();
        $latestTableUpdateAt = IndexWatchItem::query()
            ->whereNotNull('updated_at')
            ->latest('updated_at')
            ->first()
            ?->updated_at;

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
            'table_name' => (new IndexWatchItem)->getTable(),
            'table_row_count' => IndexWatchItem::query()->count(),
            'latest_table_update_at' => $latestTableUpdateAt
                ? Carbon::parse($latestTableUpdateAt)->toIso8601String()
                : null,
        ];
    }

    public function updateSettings(
        int $tradingIntervalMinutes,
        int $tradingStartsBeforeMinutes,
        int $tradingEndsAfterMinutes,
        bool $closedRefreshEnabled,
        int $closedIntervalMinutes,
    ): void {
        $this->storeSettings([
            ...$this->settings(),
            'trading_interval_minutes' => $tradingIntervalMinutes,
            'trading_starts_before_minutes' => $tradingStartsBeforeMinutes,
            'trading_ends_after_minutes' => $tradingEndsAfterMinutes,
            'closed_refresh_enabled' => $closedRefreshEnabled,
            'closed_interval_minutes' => $closedIntervalMinutes,
        ]);
    }

    public function dispatchDueRefreshes(?User $recipient = null): int
    {
        $settings = $this->settings();
        $isTradingTime = $this->isAnyIndexWithinTradingTimes(settings: $settings);

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return 0;
        }

        $nextRefreshAt = $this->carbon($settings['next_refresh_at']);

        if ($nextRefreshAt !== null && $nextRefreshAt->isFuture()) {
            return 0;
        }

        return $this->dispatchIndexRefresh($recipient);
    }

    public function dispatchOverdueRefreshes(?User $recipient = null): int
    {
        $config = AppConfig::query()
            ->where('key', self::ConfigKey)
            ->first();

        if (! $config) {
            return 0;
        }

        $settings = $this->normalizeSettings($config->value);
        $nextRefreshAt = $this->carbon($settings['next_refresh_at']);

        if ($nextRefreshAt === null || $nextRefreshAt->isFuture()) {
            return 0;
        }

        return $this->dispatchDueRefreshes($recipient);
    }

    public function dispatchIndexRefresh(?User $recipient = null): int
    {
        if (IndexWatchItem::query()->count() === 0) {
            return 0;
        }

        app(IndexWatchItemPriceRefresher::class)->refreshAll();
        $this->markRefreshed();

        return 1;
    }

    public function markRefreshed(?Carbon $refreshedAt = null): array
    {
        $refreshedAt ??= now();
        $settings = $this->settings();
        $this->storeSettings([
            ...$settings,
            'last_refreshed_at' => $refreshedAt->toIso8601String(),
            'next_refresh_at' => $this->nextRefreshAt($refreshedAt, $settings),
        ]);

        return $this->payload();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function currentIntervalMinutes(array $settings, bool $isTradingTime): int
    {
        return $settings['trading_interval_minutes'];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        $isTradingTime = $this->isAnyIndexWithinTradingTimes($from, $settings);

        if (! $isTradingTime) {
            return $this->nextTradingRefreshAt($from, $settings)->toIso8601String();
        }

        return $from
            ->copy()
            ->addMinutes($this->currentIntervalMinutes($settings, $isTradingTime))
            ->toIso8601String();
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
        $isTradingTime = $this->isAnyIndexWithinTradingTimes($from, $settings);

        if (! $isTradingTime) {
            return $this->nextTradingRefreshAt($from, $settings)->toIso8601String();
        }

        $lastRefreshedAt = $this->carbon($settings['last_refreshed_at']);

        if ($lastRefreshedAt !== null) {
            return $lastRefreshedAt
                ->copy()
                ->addMinutes($settings['trading_interval_minutes'])
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
        $hasRegularTradingWindow = $this->hasRegularTradingWindow($settings);

        foreach (IndexWatchItem::query()->whereNotNull('trading_times')->cursor() as $item) {
            if ($hasRegularTradingWindow && $this->isFullDayTradingWindow((string) $item->trading_times, $settings)) {
                continue;
            }

            $indexTradingStartsAt = $this->nextTradingStartsAt((string) $item->trading_times, $from, $settings);

            if ($indexTradingStartsAt === null) {
                continue;
            }

            if ($nextTradingStartsAt === null || $indexTradingStartsAt->lessThan($nextTradingStartsAt)) {
                $nextTradingStartsAt = $indexTradingStartsAt;
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

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}|null  $settings
     */
    private function isAnyIndexWithinTradingTimes(?Carbon $at = null, ?array $settings = null): bool
    {
        $now = ($at ?? now())->copy();
        $settings ??= $this->settings();
        $hasRegularTradingWindow = $this->hasRegularTradingWindow($settings);

        return IndexWatchItem::query()
            ->whereNotNull('trading_times')
            ->cursor()
            ->contains(fn (IndexWatchItem $item): bool => (! $hasRegularTradingWindow || ! $this->isFullDayTradingWindow((string) $item->trading_times, $settings))
                && $this->isTradingTime((string) $item->trading_times, $now, $settings));
    }

    private function hasRunningCombinedRefresh(): bool
    {
        $stockHoldingCount = StockHolding::query()->count();

        return StockPriceRefreshRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->where('total_count', '>', $stockHoldingCount)
            ->exists();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function hasRegularTradingWindow(array $settings): bool
    {
        return IndexWatchItem::query()
            ->whereNotNull('trading_times')
            ->cursor()
            ->contains(fn (IndexWatchItem $item): bool => ! $this->isFullDayTradingWindow((string) $item->trading_times, $settings));
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, last_refreshed_at: ?string, next_refresh_at: ?string}  $settings
     */
    private function isFullDayTradingWindow(string $tradingTimes, array $settings): bool
    {
        $window = $this->tradingWindow($tradingTimes, $settings);

        if ($window === null) {
            return false;
        }

        return $window['open_minute'] <= 0 && $window['close_minute'] >= 1439;
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
