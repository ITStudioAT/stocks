<?php

namespace App\Services;

use App\Models\AppConfig;
use Illuminate\Support\Carbon;

class EndOfDayDataUpdateScheduler
{
    private const ConfigKey = 'end_of_day_data_update.schedule';

    private const Timezone = 'Europe/Vienna';

    public function __construct(
        private EodhdEndOfDayDataService $endOfDayDataService,
        private StockHistoricalPriceService $historicalPriceService,
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
        return [
            ...$this->settings(),
            'status' => 'waiting',
            'status_label' => 'waiting',
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

    public function dispatchDue(): int
    {
        $settings = $this->settings();
        $now = now(self::Timezone);
        $scheduledAt = $this->scheduledAt($now, $settings['daily_time']);
        $nextRefreshAt = Carbon::parse($settings['next_refresh_at'], self::Timezone);

        if ($this->hasMissingEndOfDayData()) {
            if ($now->lessThan($nextRefreshAt)) {
                return 0;
            }

            $this->dispatchNow();

            return 1;
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

        $this->dispatchNow();

        return 1;
    }

    /**
     * @return array{
     *     requested_count: int,
     *     stored_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     date_from: string,
     *     date_to: string,
     *     errors: array<int, string>,
     * }
     */
    public function dispatchNow(): array
    {
        $result = $this->endOfDayDataService->syncLatestMissing();

        $this->markRefreshed();

        return $result;
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
        if ($this->hasMissingEndOfDayData() && $settings['last_dispatched_at'] !== null) {
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

    private function hasMissingEndOfDayData(): bool
    {
        return $this->historicalPriceService->coverage()['end_of_day_outdated_stocks'] !== [];
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
