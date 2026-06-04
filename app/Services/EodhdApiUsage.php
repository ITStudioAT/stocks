<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class EodhdApiUsage
{
    /**
     * @return array{hour: array{used: int, limit: int, remaining: int, reset_at: string}, day: array{used: int, limit: int, remaining: int, reset_at: string}}
     */
    public function payload(): array
    {
        $now = $this->now();
        $this->ensureCounters($now);

        return [
            'hour' => $this->periodPayload(
                used: $this->count($this->hourKey($now)),
                limit: $this->hourlyLimit(),
                resetAt: $now->copy()->addHour()->startOfHour(),
            ),
            'day' => $this->periodPayload(
                used: $this->count($this->dayKey($now)),
                limit: $this->dailyLimit(),
                resetAt: $now->copy()->addDay()->startOfDay(),
            ),
        ];
    }

    public function recordCall(): void
    {
        $now = $this->now();
        $this->ensureCounters($now);

        Cache::increment($this->hourKey($now));
        Cache::increment($this->dayKey($now));
    }

    private function ensureCounters(Carbon $now): void
    {
        Cache::add($this->hourKey($now), 0, $now->copy()->addHour()->startOfHour());
        Cache::add($this->dayKey($now), $this->initialDailyCalls(), $now->copy()->addDay()->startOfDay());
    }

    /**
     * @return array{used: int, limit: int, remaining: int, reset_at: string}
     */
    private function periodPayload(int $used, int $limit, Carbon $resetAt): array
    {
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max($limit - $used, 0),
            'reset_at' => $resetAt->toIso8601String(),
        ];
    }

    private function count(string $key): int
    {
        $value = Cache::get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function hourlyLimit(): int
    {
        return max((int) config('services.eodhd.calls_per_hour', 0), 0);
    }

    private function dailyLimit(): int
    {
        return max((int) config('services.eodhd.calls_per_day', 0), 0);
    }

    private function initialDailyCalls(): int
    {
        return max((int) config('services.eodhd.calls_used_today', 0), 0);
    }

    private function hourKey(Carbon $now): string
    {
        return 'eodhd-api-usage:hour:'.$now->format('Y-m-d-H');
    }

    private function dayKey(Carbon $now): string
    {
        return 'eodhd-api-usage:day:'.$now->format('Y-m-d');
    }

    private function now(): Carbon
    {
        return now(config('app.timezone', 'UTC'));
    }
}
