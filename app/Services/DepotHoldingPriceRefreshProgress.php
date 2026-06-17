<?php

namespace App\Services;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\StockPriceRefreshRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class DepotHoldingPriceRefreshProgress
{
    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    public function start(string $refreshId, int $total): array
    {
        return $this->put($refreshId, [
            'refresh_id' => $refreshId,
            'status' => $total === 0 ? 'finished' : 'queued',
            'processed' => 0,
            'total' => $total,
            'step' => "0/{$total}",
            'message' => $total === 0
                ? 'No prices queued for refresh.'
                : trans_choice('{1} 1 price queued for refresh.|[2,*] :count prices queued for refresh.', $total),
            'current' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => $total === 0 ? now()->toIso8601String() : null,
            'error' => null,
        ]);
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function get(string $refreshId): ?array
    {
        $payload = Cache::get($this->key($refreshId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function getStored(string $refreshId): ?array
    {
        $progress = $this->get($refreshId);
        $run = StockPriceRefreshRun::query()->find($refreshId);

        if (! $run) {
            return $progress;
        }

        if ($this->hasCompletedRun($run)) {
            return $this->progressFromRun($run);
        }

        if ($this->hasProcessedAllItems($run)) {
            $this->finishStoredRun($run);

            return $this->put((string) $run->id, $this->progressFromRun($run->refresh()));
        }

        if ($this->isOrphaned($run)) {
            $this->failStoredRun($run);

            return $this->put((string) $run->id, $this->progressFromRun($run->refresh()));
        }

        if ($progress !== null) {
            return $progress;
        }

        return $this->progressFromRun($run);
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function markRunning(string $refreshId): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'running',
            'message' => 'Refreshing prices...',
        ]);
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
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
                'message' => "Refreshing prices ({$processed}/{$payload['total']})...",
                'current' => $current,
            ];
        });
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function finish(string $refreshId): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'finished',
            'processed' => $payload['total'],
            'step' => "{$payload['total']}/{$payload['total']}",
            'message' => trans_choice('{0} No prices refreshed.|{1} 1 price refreshed.|[2,*] :count prices refreshed.', $payload['total']),
            'current' => null,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function fail(string $refreshId, string $error): ?array
    {
        return $this->update($refreshId, fn (array $payload): array => [
            ...$payload,
            'status' => 'failed',
            'message' => 'Price refresh failed.',
            'finished_at' => now()->toIso8601String(),
            'error' => Str::limit($error, 255, ''),
        ]);
    }

    /**
     * @param  callable(array): array  $callback
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
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
     * @param  array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}  $payload
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    private function put(string $refreshId, array $payload): array
    {
        Cache::put($this->key($refreshId), $payload, now()->addHours(2));

        return $payload;
    }

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    private function progressFromRun(StockPriceRefreshRun $run): array
    {
        return [
            'refresh_id' => (string) $run->id,
            'status' => $run->status,
            'processed' => $run->processed_count,
            'total' => $run->total_count,
            'step' => "{$run->processed_count}/{$run->total_count}",
            'message' => $this->messageFromRun($run),
            'current' => null,
            'started_at' => $run->started_at?->toIso8601String() ?? now()->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => is_array($run->error_summary) ? ($run->error_summary['message'] ?? null) : null,
        ];
    }

    private function messageFromRun(StockPriceRefreshRun $run): string
    {
        if ($run->status === 'queued') {
            return trans_choice('{1} 1 price queued for refresh.|[2,*] :count prices queued for refresh.', $run->total_count);
        }

        if ($run->status === 'running') {
            return "Refreshing prices ({$run->processed_count}/{$run->total_count})...";
        }

        if ($run->status === 'failed') {
            return 'Price refresh failed.';
        }

        return trans_choice('{0} No prices refreshed.|{1} 1 price refreshed.|[2,*] :count prices refreshed.', $run->processed_count);
    }

    private function hasCompletedRun(StockPriceRefreshRun $run): bool
    {
        return ! in_array($run->status, ['queued', 'running'], true) || $run->finished_at !== null;
    }

    private function hasProcessedAllItems(StockPriceRefreshRun $run): bool
    {
        return $run->total_count > 0 && $run->processed_count >= $run->total_count;
    }

    private function finishStoredRun(StockPriceRefreshRun $run): void
    {
        $run->update([
            'status' => $this->completedRunStatus($run),
            'finished_at' => now(),
        ]);
    }

    private function failStoredRun(StockPriceRefreshRun $run): void
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_summary' => [
                'message' => 'Price refresh stopped because no queued or running job was found.',
            ],
        ]);
    }

    private function completedRunStatus(StockPriceRefreshRun $run): string
    {
        if ($run->success_count === $run->total_count) {
            return 'finished';
        }

        if ($run->success_count > 0 || $run->suspicious_count > 0 || $run->stale_count > 0) {
            return 'partial';
        }

        return 'failed';
    }

    private function isOrphaned(StockPriceRefreshRun $run): bool
    {
        $lastActivityAt = $run->started_at ?? $run->created_at;

        if ($lastActivityAt === null || $lastActivityAt->greaterThan(now()->subSeconds($this->orphanedAfterSeconds()))) {
            return false;
        }

        return $this->queueIsEmpty();
    }

    private function orphanedAfterSeconds(): int
    {
        return (new RefreshDepotHoldingPrices('orphan-check'))->timeout + 60;
    }

    private function queueIsEmpty(): bool
    {
        $connection = (string) config('queue.default');
        $queue = (string) config("queue.connections.{$connection}.queue", 'default');

        try {
            $queueConnection = Queue::connection($connection);
            $pending = method_exists($queueConnection, 'pendingSize')
                ? $queueConnection->pendingSize($queue)
                : $queueConnection->size($queue);
            $delayed = method_exists($queueConnection, 'delayedSize') ? $queueConnection->delayedSize($queue) : 0;
            $reserved = method_exists($queueConnection, 'reservedSize') ? $queueConnection->reservedSize($queue) : 0;
        } catch (Throwable) {
            return false;
        }

        return ((int) $pending + (int) $delayed + (int) $reserved) === 0;
    }

    private function key(string $refreshId): string
    {
        return "depot-holding-price-refresh:{$refreshId}";
    }
}
