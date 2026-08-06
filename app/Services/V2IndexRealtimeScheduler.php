<?php

namespace App\Services;

use App\Jobs\SyncV2IndexRealtimeData;
use App\Models\AppConfig;
use App\Models\IndexEodhdSyncRun;
use App\Models\IndexWatchItem;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class V2IndexRealtimeScheduler
{
    private const ConfigKey = 'v2_index_realtime.schedule';

    private const LockName = 'v2-index-realtime-schedule';

    private const Timezone = 'Europe/Vienna';

    private const UpdatingTimeoutMinutes = 11;

    public function __construct(
        private IndexWatchItemPriceRefresher $priceRefresher,
        private IndexMarketHours $indexMarketHours,
    ) {}

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);
        $settings['next_refresh_at'] = $this->recalculatedNextRefreshAt(now(self::Timezone), $settings);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: string, latest_update_at: ?string, status: string, status_label: string, status_detail: string, is_trading_time: bool, current_interval_minutes: int}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $now = now(self::Timezone);
        $isTradingTime = $this->isAnyIndexWithinTradingTimes($now, $settings);
        $isUpdating = $this->isUpdating($settings);
        $hasIndices = IndexWatchItem::query()->exists();
        $isHistoricalSyncRunning = $this->isHistoricalSyncRunning();
        $nextRefreshAt = Carbon::parse($settings['next_refresh_at'], self::Timezone);
        [$status, $statusLabel, $statusDetail] = $this->status(
            $settings,
            $now,
            $nextRefreshAt,
            $isTradingTime,
            $isUpdating,
            $hasIndices,
            $isHistoricalSyncRunning,
        );

        return [
            ...$settings,
            'latest_update_at' => $settings['last_refreshed_at'],
            'status' => $status,
            'status_label' => $statusLabel,
            'status_detail' => $statusDetail,
            'is_trading_time' => $isTradingTime,
            'current_interval_minutes' => $this->currentIntervalMinutes($settings, $isTradingTime),
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: string}  $settings
     * @return array{string, string, string}
     */
    private function status(
        array $settings,
        Carbon $now,
        Carbon $nextRefreshAt,
        bool $isTradingTime,
        bool $isUpdating,
        bool $hasIndices,
        bool $isHistoricalSyncRunning,
    ): array {
        if ($isUpdating) {
            return ['updating', 'Updating now', 'The realtime refresh job is running.'];
        }

        if (! $hasIndices) {
            return ['inactive', 'No indices', 'Add an index to activate realtime updates.'];
        }

        if ($isHistoricalSyncRunning) {
            return ['paused', 'Paused', 'Waiting for the EOD and 5-minute intraday sync to finish.'];
        }

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return ['market_closed', 'Market closed', 'Closed-market refreshes are off; updates resume in the next trading window.'];
        }

        if ($now->greaterThanOrEqualTo($nextRefreshAt)) {
            return ['due', 'Due now', 'Waiting for the minute scheduler to enqueue the refresh.'];
        }

        if ($settings['last_error'] !== null) {
            return ['retry_scheduled', 'Retry scheduled', 'The last refresh failed; another attempt is scheduled.'];
        }

        return ['scheduled', 'Scheduled', 'No job is running; the next refresh starts at the time shown below.'];
    }

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: string, latest_update_at: ?string, status: string, status_label: string, status_detail: string, is_trading_time: bool, current_interval_minutes: int}
     */
    public function updateSettings(
        int $tradingIntervalMinutes,
        int $tradingStartsBeforeMinutes,
        int $tradingEndsAfterMinutes,
        bool $closedRefreshEnabled,
        int $closedIntervalMinutes,
    ): array {
        $settings = [
            ...$this->settings(),
            'trading_interval_minutes' => $tradingIntervalMinutes,
            'trading_starts_before_minutes' => $tradingStartsBeforeMinutes,
            'trading_ends_after_minutes' => $tradingEndsAfterMinutes,
            'closed_refresh_enabled' => $closedRefreshEnabled,
            'closed_interval_minutes' => $closedIntervalMinutes,
        ];
        $settings['next_refresh_at'] = $this->recalculatedNextRefreshAt(now(self::Timezone), $settings);
        $this->storeSettings($settings);

        return $this->payload();
    }

    public function dispatchDue(): int
    {
        $result = Cache::lock(self::LockName, 60)->get(function (): int {
            $settings = $this->settings();
            $now = now(self::Timezone);

            if ($this->isUpdating($settings) || $this->isHistoricalSyncRunning()) {
                return 0;
            }

            $isTradingTime = $this->isAnyIndexWithinTradingTimes($now, $settings);

            if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
                return 0;
            }

            if ($now->lessThan(Carbon::parse($settings['next_refresh_at'], self::Timezone))) {
                return 0;
            }

            if (! IndexWatchItem::query()->exists()) {
                return 0;
            }

            $settings['last_dispatched_at'] = $now->toIso8601String();
            $settings['next_refresh_at'] = $this->nextRefreshAt($now, $settings)->toIso8601String();
            $settings['last_error'] = null;
            $this->storeSettings($settings);

            try {
                SyncV2IndexRealtimeData::dispatch();
            } catch (Throwable $exception) {
                $this->markFailed($exception->getMessage());

                throw $exception;
            }

            return 1;
        });

        return (int) $result;
    }

    /** @return array{requested_count: int, refreshed_count: int, failed_count: int} */
    public function syncNow(): array
    {
        $startedAt = now(self::Timezone);
        $settings = $this->settings();
        $indexWatchItemIds = $settings['closed_refresh_enabled']
            ? IndexWatchItem::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
            : $this->indexIdsWithinTradingTimes($startedAt, $settings);
        $result = $this->priceRefresher->refreshIds($indexWatchItemIds);
        $finishedAt = now(self::Timezone);
        $this->storeSettings([
            ...$settings,
            'last_refreshed_at' => $finishedAt->toIso8601String(),
            'last_finished_at' => $finishedAt->toIso8601String(),
            'last_error' => $result['failed_count'] > 0
                ? "{$result['failed_count']} index live price refresh(es) failed."
                : null,
            'next_refresh_at' => $this->nextRefreshAt($finishedAt, $settings)->toIso8601String(),
        ]);

        return $result;
    }

    public function markFailed(string $message): void
    {
        $settings = $this->settings();

        $this->storeSettings([
            ...$settings,
            'last_finished_at' => now(self::Timezone)->toIso8601String(),
            'last_error' => $message,
        ]);
    }

    /**
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: null, last_refreshed_at: null, last_finished_at: null, last_error: null, next_refresh_at: null}
     */
    private function defaultSettings(): array
    {
        return [
            'trading_interval_minutes' => 20,
            'trading_starts_before_minutes' => 0,
            'trading_ends_after_minutes' => 0,
            'closed_refresh_enabled' => false,
            'closed_interval_minutes' => 60,
            'timezone' => self::Timezone,
            'last_dispatched_at' => null,
            'last_refreshed_at' => null,
            'last_finished_at' => null,
            'last_error' => null,
            'next_refresh_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'trading_interval_minutes' => max((int) $settings['trading_interval_minutes'], 1),
            'trading_starts_before_minutes' => max((int) $settings['trading_starts_before_minutes'], 0),
            'trading_ends_after_minutes' => max((int) $settings['trading_ends_after_minutes'], 0),
            'closed_refresh_enabled' => (bool) $settings['closed_refresh_enabled'],
            'closed_interval_minutes' => max((int) $settings['closed_interval_minutes'], 1),
            'timezone' => self::Timezone,
            'last_dispatched_at' => is_string($settings['last_dispatched_at']) ? $settings['last_dispatched_at'] : null,
            'last_refreshed_at' => is_string($settings['last_refreshed_at']) ? $settings['last_refreshed_at'] : null,
            'last_finished_at' => is_string($settings['last_finished_at']) ? $settings['last_finished_at'] : null,
            'last_error' => is_string($settings['last_error']) ? $settings['last_error'] : null,
            'next_refresh_at' => is_string($settings['next_refresh_at']) ? $settings['next_refresh_at'] : null,
        ];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function recalculatedNextRefreshAt(Carbon $from, array $settings): string
    {
        $isTradingTime = $this->isAnyIndexWithinTradingTimes($from, $settings);

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return $this->nextTradingRefreshAt($from, $settings)->toIso8601String();
        }

        if ($settings['next_refresh_at'] !== null && $this->isUpdating($settings, $from)) {
            return $settings['next_refresh_at'];
        }

        $lastRefreshedAt = $this->carbon($settings['last_refreshed_at']);

        if ($lastRefreshedAt !== null) {
            return $lastRefreshedAt
                ->copy()
                ->addMinutes($this->currentIntervalMinutes($settings, $isTradingTime))
                ->toIso8601String();
        }

        if ($settings['last_dispatched_at'] === null) {
            return $from->toIso8601String();
        }

        return $settings['next_refresh_at'] ?? $this->nextRefreshAt($from, $settings)->toIso8601String();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): Carbon
    {
        $isTradingTime = $this->isAnyIndexWithinTradingTimes($from, $settings);

        if (! $isTradingTime && ! $settings['closed_refresh_enabled']) {
            return $this->nextTradingRefreshAt($from, $settings);
        }

        return $from
            ->copy()
            ->addMinutes($this->currentIntervalMinutes($settings, $isTradingTime));
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function currentIntervalMinutes(array $settings, bool $isTradingTime): int
    {
        return $isTradingTime
            ? $settings['trading_interval_minutes']
            : $settings['closed_interval_minutes'];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextTradingRefreshAt(Carbon $from, array $settings): Carbon
    {
        $nextTradingStartsAt = null;
        $hasRegularTradingWindow = $this->hasRegularTradingWindow($settings);

        foreach (IndexWatchItem::query()->cursor() as $item) {
            $tradingTimes = $this->indexMarketHours->effectiveTradingTimes($item);

            if ($tradingTimes === null) {
                continue;
            }

            if ($hasRegularTradingWindow && $this->isFullDayTradingWindow($tradingTimes, $settings)) {
                continue;
            }

            $candidate = $this->nextTradingStartsAt($tradingTimes, $from, $settings);

            if ($candidate !== null && ($nextTradingStartsAt === null || $candidate->lessThan($nextTradingStartsAt))) {
                $nextTradingStartsAt = $candidate;
            }
        }

        return $nextTradingStartsAt ?? $from->copy()->addMinutes($settings['trading_interval_minutes']);
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
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
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function isAnyIndexWithinTradingTimes(?Carbon $at = null, ?array $settings = null): bool
    {
        $now = ($at ?? now(self::Timezone))->copy();
        $settings ??= $this->settings();

        return $this->indexIdsWithinTradingTimes($now, $settings) !== [];
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     * @return array<int, int>
     */
    private function indexIdsWithinTradingTimes(Carbon $at, array $settings): array
    {
        $hasRegularTradingWindow = $this->hasRegularTradingWindow($settings);

        return IndexWatchItem::query()
            ->orderBy('id')
            ->cursor()
            ->filter(function (IndexWatchItem $item) use ($at, $hasRegularTradingWindow, $settings): bool {
                $tradingTimes = $this->indexMarketHours->effectiveTradingTimes($item);

                if ($tradingTimes === null) {
                    return false;
                }

                return (! $hasRegularTradingWindow || ! $this->isFullDayTradingWindow($tradingTimes, $settings))
                    && $this->isTradingTime($tradingTimes, $at, $settings);
            })
            ->map(fn (IndexWatchItem $item): int => (int) $item->getKey())
            ->values()
            ->all();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function hasRegularTradingWindow(array $settings): bool
    {
        return IndexWatchItem::query()
            ->cursor()
            ->contains(function (IndexWatchItem $item) use ($settings): bool {
                $tradingTimes = $this->indexMarketHours->effectiveTradingTimes($item);

                return $tradingTimes !== null && ! $this->isFullDayTradingWindow($tradingTimes, $settings);
            });
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
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
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
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
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     * @return array{timezone: string, open_minute: int, close_minute: int}|null
     */
    private function tradingWindow(string $tradingTimes, array $settings): ?array
    {
        if (! preg_match('/(?<![:\d])(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return null;
        }

        $timezone = $this->tradingTimezone($tradingTimes);

        return [
            'timezone' => $timezone,
            'open_minute' => (((int) $matches['open_hour'] * 60) + (int) $matches['open_minute']) - $settings['trading_starts_before_minutes'],
            'close_minute' => (((int) $matches['close_hour'] * 60) + (int) $matches['close_minute']) + $settings['trading_ends_after_minutes'],
        ];
    }

    private function tradingTimezone(string $tradingTimes): string
    {
        if (! preg_match('/(?<timezone>(?:[A-Za-z0-9_+\-]+\/)+[A-Za-z0-9_+\-]+|UTC)\s*$/', trim($tradingTimes), $matches)) {
            return self::Timezone;
        }

        try {
            return (new DateTimeZone($matches['timezone']))->getName();
        } catch (Throwable) {
            return self::Timezone;
        }
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
     */
    private function isUpdating(array $settings, ?Carbon $at = null): bool
    {
        $lastDispatchedAt = $this->carbon($settings['last_dispatched_at']);
        $lastFinishedAt = $this->carbon($settings['last_finished_at']);

        if ($lastDispatchedAt === null) {
            return false;
        }

        if ($lastFinishedAt !== null && $lastFinishedAt->greaterThanOrEqualTo($lastDispatchedAt)) {
            return false;
        }

        return $lastDispatchedAt->greaterThan(
            ($at ?? now(self::Timezone))->copy()->subMinutes(self::UpdatingTimeoutMinutes),
        );
    }

    private function isHistoricalSyncRunning(): bool
    {
        return IndexEodhdSyncRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->exists();
    }

    /**
     * @param  array{trading_interval_minutes: int, trading_starts_before_minutes: int, trading_ends_after_minutes: int, closed_refresh_enabled: bool, closed_interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_refreshed_at: ?string, last_finished_at: ?string, last_error: ?string, next_refresh_at: ?string}  $settings
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
        return $value === null ? null : Carbon::parse($value, self::Timezone);
    }
}
