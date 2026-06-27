<?php

namespace App\Services;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Support\Str;

class DepotHoldingPriceRefreshDispatcher
{
    public function __construct(
        private DepotHoldingPriceRefreshProgress $refreshProgress,
    ) {}

    /**
     * @return array{refresh_id: string, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}|null
     */
    public function dispatch(?User $recipient = null): ?array
    {
        $total = StockHolding::query()->count();

        if ($total === 0) {
            return null;
        }

        $refreshId = (string) Str::uuid();
        $progress = $this->refreshProgress->start($refreshId, $total);

        StockPriceRefreshRun::query()->create([
            'id' => $refreshId,
            'status' => 'queued',
            'total_count' => $total,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        RefreshDepotHoldingPrices::dispatch($refreshId, $recipient?->id);

        return $this->refreshProgress->get($refreshId) ?? $progress;
    }
}
