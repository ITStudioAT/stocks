<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class HistoricalSessionStartPriceFetchStatus
{
    public function queued(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): void
    {
        $this->put($stockHoldingId, $from, $until, $priceType, 'queued', now()->addHours(2));
    }

    public function running(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): void
    {
        $this->put($stockHoldingId, $from, $until, $priceType, 'running', now()->addHours(2));
    }

    public function finished(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): void
    {
        $this->put($stockHoldingId, $from, $until, $priceType, 'finished', now()->addHours(6));
    }

    public function failed(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): void
    {
        $this->put($stockHoldingId, $from, $until, $priceType, 'failed', now()->addMinutes(10));
    }

    public function isFetching(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): bool
    {
        return in_array($this->status($stockHoldingId, $from, $until, $priceType), ['queued', 'running'], true);
    }

    public function hasTerminalStatus(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType = 'start'): bool
    {
        return in_array($this->status($stockHoldingId, $from, $until, $priceType), ['finished', 'failed'], true);
    }

    private function status(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType): ?string
    {
        $status = Cache::get($this->key($stockHoldingId, $from, $until, $priceType));

        return is_string($status) ? $status : null;
    }

    private function put(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType, string $status, Carbon $expiresAt): void
    {
        Cache::put($this->key($stockHoldingId, $from, $until, $priceType), $status, $expiresAt);
    }

    private function key(int $stockHoldingId, Carbon $from, Carbon $until, string $priceType): string
    {
        return 'historical-session-start-price:'
            .$priceType.':'
            .$stockHoldingId.':'
            .$from->copy()->utc()->toIso8601String().':'
            .$until->copy()->utc()->toIso8601String();
    }
}
