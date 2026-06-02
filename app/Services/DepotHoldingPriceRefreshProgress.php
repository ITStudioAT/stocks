<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DepotHoldingPriceRefreshProgress
{
    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    public function start(string $refreshId, int $depotId, int $total): array
    {
        return $this->put($refreshId, [
            'refresh_id' => $refreshId,
            'depot_id' => $depotId,
            'status' => $total === 0 ? 'finished' : 'queued',
            'processed' => 0,
            'total' => $total,
            'step' => "0/{$total}",
            'message' => $total === 0
                ? 'No stock prices queued for refresh.'
                : trans_choice('{1} 1 stock price queued for refresh.|[2,*] :count stock prices queued for refresh.', $total),
            'current' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => $total === 0 ? now()->toIso8601String() : null,
            'error' => null,
        ]);
    }

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function get(string $refreshId): ?array
    {
        $payload = Cache::get($this->key($refreshId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function markRunning(string $refreshId): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'running',
            'message' => 'Refreshing stock prices...',
        ]);
    }

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function advance(string $refreshId, ?string $current): ?array
    {
        return $this->update($refreshId, function (array $payload) use ($current): array {
            $processed = min($payload['total'], $payload['processed'] + 1);

            return [
                ...$payload,
                'status' => 'running',
                'processed' => $processed,
                'step' => "{$processed}/{$payload['total']}",
                'message' => "Refreshing stock prices ({$processed}/{$payload['total']})...",
                'current' => $current,
            ];
        });
    }

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function finish(string $refreshId): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'finished',
            'processed' => $payload['total'],
            'step' => "{$payload['total']}/{$payload['total']}",
            'message' => trans_choice('{0} No stock prices refreshed.|{1} 1 stock price refreshed.|[2,*] :count stock prices refreshed.', $payload['total']),
            'current' => null,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function fail(string $refreshId, string $error): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'failed',
            'message' => 'Stock price refresh failed.',
            'finished_at' => now()->toIso8601String(),
            'error' => Str::limit($error, 255, ''),
        ]);
    }

    /**
     * @param  callable(array): array  $callback
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    private function update(string $refreshId, callable $callback): ?array
    {
        $payload = $this->get($refreshId);

        if ($payload === null) {
            return null;
        }

        return $this->put($refreshId, $callback($payload));
    }

    /**
     * @param  array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}  $payload
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    private function put(string $refreshId, array $payload): array
    {
        Cache::put($this->key($refreshId), $payload, now()->addHours(2));

        return $payload;
    }

    private function key(string $refreshId): string
    {
        return "depot-holding-price-refresh:{$refreshId}";
    }
}
