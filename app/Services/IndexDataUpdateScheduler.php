<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\IndexWatchItemPrice;
use Illuminate\Support\Carbon;

class IndexDataUpdateScheduler
{
    private const ConfigKey = 'index_data_update.schedule';

    private const Timezone = 'Europe/Vienna';

    private const DailyTime = '02:00';

    public function __construct(
        private IndexWatchItemPriceRefresher $priceRefresher,
    ) {}

    /**
     * @return array{weekday: int, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
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
     * @return array{weekday: int, weekday_label: string, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string, status: string, status_label: string, table_name: string, table_row_count: int, latest_table_update_at: ?string}
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $latestTableUpdateAt = $this->latestTableUpdateAt();

        return [
            ...$settings,
            'weekday_label' => $this->weekdayLabel($settings['weekday']),
            'status' => 'waiting',
            'status_label' => 'waiting',
            'table_name' => (new IndexWatchItemPrice)->getTable(),
            'table_row_count' => IndexWatchItemPrice::query()->count(),
            'latest_table_update_at' => $latestTableUpdateAt
                ? Carbon::parse($latestTableUpdateAt)->toIso8601String()
                : null,
        ];
    }

    public function updateSettings(int $weekday): array
    {
        $settings = [
            ...$this->settings(),
            'weekday' => max(1, min(5, $weekday)),
        ];
        $settings['next_refresh_at'] = $this->nextRefreshAt(now(self::Timezone), $settings);
        $this->storeSettings($settings);

        return $this->payload();
    }

    public function dispatchDue(): int
    {
        $settings = $this->settings();
        $nextRefreshAt = Carbon::parse($settings['next_refresh_at'], self::Timezone);

        if (now(self::Timezone)->lessThan($nextRefreshAt)) {
            return 0;
        }

        $this->dispatchNow();

        return 1;
    }

    /**
     * @return array{requested_count: int, stored_count: int}
     */
    public function dispatchNow(): array
    {
        $result = $this->priceRefresher->syncHistoricalDailyPricesForAll();
        $this->markRefreshed();

        return $result;
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

        return $this->payload();
    }

    /**
     * @return array{weekday: int, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
     */
    private function defaultSettings(): array
    {
        return [
            'weekday' => 1,
            'daily_time' => self::DailyTime,
            'timezone' => self::Timezone,
            'last_dispatched_at' => null,
            'last_dispatched_on' => null,
            'next_refresh_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{weekday: int, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'weekday' => max(1, min(5, (int) $settings['weekday'])),
            'daily_time' => self::DailyTime,
            'timezone' => self::Timezone,
            'last_dispatched_at' => is_string($settings['last_dispatched_at']) ? $settings['last_dispatched_at'] : null,
            'last_dispatched_on' => is_string($settings['last_dispatched_on']) ? $settings['last_dispatched_on'] : null,
            'next_refresh_at' => is_string($settings['next_refresh_at']) ? $settings['next_refresh_at'] : null,
        ];
    }

    /**
     * @param  array{weekday: int, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}  $settings
     */
    private function nextRefreshAt(Carbon $from, array $settings): string
    {
        $scheduledAt = $this->scheduledAt($from, $settings['weekday']);

        if ($settings['last_dispatched_on'] === $scheduledAt->toDateString() || $scheduledAt->lessThanOrEqualTo($from)) {
            return $scheduledAt->addWeek()->toIso8601String();
        }

        return $scheduledAt->toIso8601String();
    }

    private function scheduledAt(Carbon $from, int $weekday): Carbon
    {
        return $from
            ->copy()
            ->setTimezone(self::Timezone)
            ->startOfWeek()
            ->addDays($weekday - 1)
            ->setTime(2, 0);
    }

    private function weekdayLabel(int $weekday): string
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
        ][$weekday] ?? 'Monday';
    }

    private function latestTableUpdateAt(): ?Carbon
    {
        return IndexWatchItemPrice::query()
            ->whereNotNull('updated_at')
            ->latest('updated_at')
            ->first()
            ?->updated_at;
    }

    /**
     * @param  array{weekday: int, daily_time: string, timezone: string, last_dispatched_at: ?string, last_dispatched_on: ?string, next_refresh_at: ?string}  $settings
     */
    private function storeSettings(array $settings): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );
    }
}
