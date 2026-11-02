<?php

namespace App\Services;

use App\Jobs\BackfillMissingStockHoldingIntradayCandles;
use App\Models\AppConfig;
use App\Models\StockHoldingIntradayReloadRun;
use Illuminate\Support\Carbon;

class IntradayCandleBackfillScheduler
{
    private const ConfigKey = 'intraday_candle_backfill.schedule';

    private const Timezone = 'Europe/Vienna';

    public function __construct(
        private StockHoldingIntradayDataReloader $reloader,
        private StockHistoricalDataRepairService $historicalDataRepairService,
    ) {}

    /**
     * @return array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);
        $settings['next_refresh_at'] = $this->nextRefreshAt(now(self::Timezone), $settings);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string, status: string, status_label: string}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $isRunning = $this->reloader->runningRun() !== null;

        return [
            ...$settings,
            'status' => $isRunning ? 'updating' : 'waiting',
            'status_label' => $isRunning ? 'Importing recent intraday data' : 'waiting',
        ];
    }

    public function updateSettings(string $dailyTime, ?int $intervalMinutes = null): array
    {
        $settings = [
            ...$this->settings(),
            'daily_time' => $dailyTime,
        ];

        if ($intervalMinutes !== null) {
            $settings['interval_minutes'] = $intervalMinutes;
        }

        $settings['next_refresh_at'] = $this->nextRefreshAt(now(self::Timezone), $settings);
        $this->storeSettings($settings);

        return $this->settings();
    }

    public function dispatchDue(): int
    {
        if ($this->reloader->runningRun() !== null) {
            return 0;
        }

        $settings = $this->settings();
        $now = now(self::Timezone);
        $scheduledAt = $this->scheduledAt($now, $settings['daily_time']);
        $nextRefreshAt = Carbon::parse($settings['next_refresh_at'], self::Timezone);

        if ($this->hasMissingHistoricalData()) {
            if ($now->lessThan($nextRefreshAt)) {
                return 0;
            }

            return $this->dispatchNow() ? 1 : 0;
        }

        if (! $this->isDispatchDay($now)) {
            return 0;
        }

        if ($now->lessThan($scheduledAt)) {
            return 0;
        }

        if ($settings['last_dispatched_on'] === $now->toDateString()) {
            return 0;
        }

        return $this->dispatchNow() ? 1 : 0;
    }

    public function dispatchNow(): ?StockHoldingIntradayReloadRun
    {
        $run = $this->reloader->runningRun();

        if (! $run) {
            $run = $this->reloader->createRecentRun();

            BackfillMissingStockHoldingIntradayCandles::dispatch($run->id, recentOnly: true);
        }

        $now = now(self::Timezone);
        $settings = [
            ...$this->settings(),
            'last_dispatched_at' => $now->toIso8601String(),
            'last_dispatched_on' => $now->toDateString(),
        ];
        $settings['next_refresh_at'] = $this->nextRefreshAt($now, $settings);
        $this->storeSettings($settings);

        return $run;
    }

    public function markRefreshed(?Carbon $refreshedAt = null): array
    {
        $refreshedAt ??= now(self::Timezone);
        $settings = [
            ...$this->settings(),
            'last_dispatched_at' => $refreshedAt->copy()->setTimezone(self::Timezone)->toIso8601String(),
            'last_dispatched_on' => $refreshedAt->copy()->setTimezone(self::Timezone)->toDateString(),
        ];
        $settings['next_refresh_at'] = $this->nextRefreshAt($refreshedAt->copy()->setTimezone(self::Timezone), $settings);
        $this->storeSettings($settings);

        return $this->settings();
    }

    /**
     * @return array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
     */
    private function defaultSettings(): array
    {
        return [
            'daily_time' => '18:30',
            'interval_minutes' => 15,
            'timezone' => self::Timezone,
            'last_dispatched_at' => null,
            'last_dispatched_on' => null,
            'next_refresh_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];
        $dailyTime = is_string($settings['daily_time']) && preg_match('/^\d{2}:\d{2}$/', $settings['daily_time']) === 1
            ? $settings['daily_time']
            : $this->defaultSettings()['daily_time'];

        return [
            'daily_time' => $dailyTime,
            'interval_minutes' => max(1, min(1440, (int) $settings['interval_minutes'])),
            'timezone' => self::Timezone,
            'last_dispatched_at' => is_string($settings['last_dispatched_at']) ? $settings['last_dispatched_at'] : null,
            'last_dispatched_on' => is_string($settings['last_dispatched_on']) ? $settings['last_dispatched_on'] : null,
            'next_refresh_at' => is_string($settings['next_refresh_at']) ? $settings['next_refresh_at'] : null,
        ];
    }

    /**
     * @param  array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        if ($this->hasMissingHistoricalData() && $settings['last_dispatched_at'] !== null) {
            return Carbon::parse($settings['last_dispatched_at'], self::Timezone)
                ->addMinutes($settings['interval_minutes'])
                ->toIso8601String();
        }

        $scheduledAt = $this->scheduledAt($from, $settings['daily_time']);

        if (! $this->isDispatchDay($scheduledAt)) {
            return $this->nextDispatchScheduledAt($scheduledAt->copy()->addDay(), $settings['daily_time'])->toIso8601String();
        }

        if ($settings['last_dispatched_on'] === $from->toDateString()) {
            return $this->nextDispatchScheduledAt($scheduledAt->copy()->addDay(), $settings['daily_time'])->toIso8601String();
        }

        return $scheduledAt->toIso8601String();
    }

    private function nextDispatchScheduledAt(Carbon $from, string $dailyTime): Carbon
    {
        $scheduledAt = $this->scheduledAt($from, $dailyTime);

        while (! $this->isDispatchDay($scheduledAt)) {
            $scheduledAt = $this->scheduledAt($scheduledAt->copy()->addDay(), $dailyTime);
        }

        return $scheduledAt;
    }

    private function isDispatchDay(Carbon $date): bool
    {
        return $date->isWeekday();
    }

    private function hasMissingHistoricalData(): bool
    {
        return $this->historicalDataRepairService->summary()['missing_stocks_count'] > 0;
    }

    private function scheduledAt(Carbon $day, string $dailyTime): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $dailyTime));

        return $day
            ->copy()
            ->setTimezone(self::Timezone)
            ->startOfDay()
            ->setTime($hour, $minute);
    }

    /**
     * @param  array{daily_time: string, interval_minutes: int, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}  $settings
     */
    private function storeSettings(array $settings): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );
    }
}
