<?php

namespace App\Services;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\Depot;
use App\Models\StockPriceRefreshRun;
use Illuminate\Support\Str;

class DepotHoldingPriceRefreshDispatcher
{
    public function __construct(
        private DepotHoldingPriceRefreshProgress $refreshProgress,
    ) {}

    /**
     * @return array{refresh_id: string, depot_id: int, status: string, processed: int, total: int, step: string, message: string, current: ?string, started_at: string, finished_at: ?string, error: ?string}
     */
    public function dispatch(Depot $depot): array
    {
        $refreshId = (string) Str::uuid();
        $total = $depot->stockHoldings()->count();
        $progress = $this->refreshProgress->start($refreshId, $depot->id, $total);

        StockPriceRefreshRun::query()->create([
            'id' => $refreshId,
            'depot_id' => $depot->id,
            'status' => $total === 0 ? 'finished' : 'queued',
            'total_count' => $total,
            'started_at' => now(),
            'finished_at' => $total === 0 ? now() : null,
        ]);

        if ($total > 0) {
            RefreshDepotHoldingPrices::dispatch($depot->id, $refreshId);

            return $this->refreshProgress->get($refreshId) ?? $progress;
        }

        return $progress;
    }
}
