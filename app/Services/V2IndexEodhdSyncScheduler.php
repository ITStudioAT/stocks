<?php

namespace App\Services;

use App\Jobs\SyncIndexEodhdData;
use App\Models\AppConfig;
use App\Models\IndexEodhdSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class V2IndexEodhdSyncScheduler
{
    private const ConfigKey = 'v2_index_eodhd_sync.schedule';

    private const LockName = 'v2-index-eodhd-sync-schedule';

    private const Timezone = 'Europe/Vienna';

    private const DefaultTimes = ['02:00'];

    public function __construct(
        private V2IndexEodhdSyncService $syncService,
        private V2IndexRealtimeScheduler $realtimeScheduler,
    ) {}

    /**
     * @return array{times: array<int, string>, timezone: string, last_dispatched_at: ?string, next_update_at: string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);

        if ($settings['next_update_at'] === null) {
            $settings['next_update_at'] = $this->nextUpdateAt(now(self::Timezone), $settings['times'])->toIso8601String();
        }

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{times: array<int, string>, timezone: string, last_dispatched_at: ?string, next_update_at: string, latest_update_at: ?string, status: string, status_label: string}
     */
    public function payload(): array
    {
        $latestCompletedRun = IndexEodhdSyncRun::query()
            ->whereIn('status', ['finished', 'partial'])
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();
        $isRunning = $this->syncService->runningRun() !== null;

        return [
            ...$this->settings(),
            'latest_update_at' => $latestCompletedRun?->finished_at?->toIso8601String(),
            'status' => $isRunning ? 'updating' : 'waiting',
            'status_label' => $isRunning ? 'Updating indices' : 'Waiting',
            'realtime' => $this->realtimeScheduler->payload(),
        ];
    }

    /**
     * @param  array<int, string>  $times
     * @return array{times: array<int, string>, timezone: string, last_dispatched_at: ?string, next_update_at: string, latest_update_at: ?string, status: string, status_label: string}
     */
    public function updateSettings(array $times): array
    {
        $normalizedTimes = $this->normalizeTimes($times);
        $settings = [
            ...$this->settings(),
            'times' => $normalizedTimes,
            'next_update_at' => $this->nextUpdateAt(now(self::Timezone), $normalizedTimes)->toIso8601String(),
        ];
        $this->storeSettings($settings);

        return $this->payload();
    }

    public function dispatchDue(): int
    {
        $result = Cache::lock(self::LockName, 60)->get(function (): int {
            $settings = $this->settings();
            $now = now(self::Timezone);
            $nextUpdateAt = Carbon::parse($settings['next_update_at'], self::Timezone);

            if ($now->lessThan($nextUpdateAt)) {
                return 0;
            }

            if ($this->syncService->runningRun() !== null) {
                $settings['next_update_at'] = $this->nextUpdateAt($now, $settings['times'])->toIso8601String();
                $this->storeSettings($settings);

                return 0;
            }

            $this->dispatchNow();
            $settings['last_dispatched_at'] = $now->toIso8601String();
            $settings['next_update_at'] = $this->nextUpdateAt($now, $settings['times'])->toIso8601String();
            $this->storeSettings($settings);

            return 1;
        });

        return (int) $result;
    }

    public function dispatchNow(): IndexEodhdSyncRun
    {
        $run = $this->syncService->runningRun();

        if ($run) {
            return $run;
        }

        $run = $this->syncService->createRun();
        SyncIndexEodhdData::dispatch($run->id);

        return $run;
    }

    /**
     * @return array{times: array<int, string>, timezone: string, last_dispatched_at: null, next_update_at: null}
     */
    private function defaultSettings(): array
    {
        return [
            'times' => self::DefaultTimes,
            'timezone' => self::Timezone,
            'last_dispatched_at' => null,
            'next_update_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{times: array<int, string>, timezone: string, last_dispatched_at: ?string, next_update_at: ?string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'times' => $this->normalizeTimes(is_array($settings['times']) ? $settings['times'] : []),
            'timezone' => self::Timezone,
            'last_dispatched_at' => is_string($settings['last_dispatched_at']) ? $settings['last_dispatched_at'] : null,
            'next_update_at' => is_string($settings['next_update_at']) ? $settings['next_update_at'] : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $times
     * @return array<int, string>
     */
    private function normalizeTimes(array $times): array
    {
        $normalizedTimes = collect($times)
            ->filter(fn (mixed $time): bool => is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalizedTimes !== [] ? $normalizedTimes : self::DefaultTimes;
    }

    /**
     * @param  array<int, string>  $times
     */
    private function nextUpdateAt(Carbon $from, array $times): Carbon
    {
        foreach ($times as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $scheduledAt = $from
                ->copy()
                ->setTimezone(self::Timezone)
                ->startOfDay()
                ->setTime($hour, $minute);

            if ($scheduledAt->greaterThan($from)) {
                return $scheduledAt;
            }
        }

        [$hour, $minute] = array_map('intval', explode(':', $times[0]));

        return $from
            ->copy()
            ->setTimezone(self::Timezone)
            ->addDay()
            ->startOfDay()
            ->setTime($hour, $minute);
    }

    /**
     * @param  array{times: array<int, string>, timezone: string, last_dispatched_at: ?string, next_update_at: ?string}  $settings
     */
    private function storeSettings(array $settings): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );
    }
}
