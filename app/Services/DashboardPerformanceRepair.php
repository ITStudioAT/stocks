<?php

namespace App\Services;

use App\Jobs\RepairDashboardPerformance;
use App\Models\Depot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class DashboardPerformanceRepair
{
    public function __construct(
        private DepotBalanceHistory $history,
        private StoredHistoricalPriceResolver $prices,
        private FreeDailyMarketData $daily,
        private DashboardPerformanceSnapshots $snapshots,
        private PreviewIsolation $preview,
    ) {}

    /**
     * @param  array{date: string}|array{period: string}  $target
     * @param  non-empty-list<string>  $dates
     * @return array<string, mixed>
     */
    public function start(Depot $depot, array $target, array $dates, ?string $completedPeriod): array
    {
        $key = $this->key($depot->id);

        return Cache::lock($key.':dispatch', 330)->get(function () use ($depot, $target, $dates, $completedPeriod, $key): array {
            $previousId = Cache::get($key);
            $previous = is_string($previousId) ? $this->find($depot->id, $previousId) : null;
            if (in_array($previous['status'] ?? null, ['queued', 'running'], true)) {
                return $previous['target'] === $target ? $previous : ['status' => 'in_progress'];
            }

            $run = ['id' => (string) Str::uuid(), 'status' => 'queued', 'target' => $target, 'dates' => $dates,
                'completed_period' => $completedPeriod];
            Cache::put($this->key($depot->id, $run['id']), $run, now()->addMinutes(15));
            Cache::put($key, $run['id'], now()->addMinutes(30));
            try {
                RepairDashboardPerformance::dispatch($depot->id, $run['id']);
            } catch (Throwable $exception) {
                report($exception);
                $this->finish($depot->id, $run['id'], 'failed');
            }

            return $this->find($depot->id, $run['id']) ?? ['status' => 'failed'];
        }) ?? ['status' => 'in_progress'];
    }

    /** @return array<string, mixed>|null */
    public function find(int $depotId, string $runId): ?array
    {
        return Cache::get($this->key($depotId, $runId));
    }

    public function process(int $depotId, string $runId): void
    {
        $run = $this->find($depotId, $runId);
        if (($run['status'] ?? null) !== 'queued') {
            return;
        }

        $this->finish($depotId, $runId, 'running');
        $depot = Depot::query()->findOrFail($depotId);
        $requests = [];
        if (MarketPriceStore::enabled() && ! $this->preview->active()) {
            foreach ($run['dates'] as $date) {
                $cutoff = Carbon::parse($date)->endOfDay();
                foreach ($this->history->missingHoldingsAt($depot, $cutoff) as $holding) {
                    $key = $holding->market_listing_id === null ? 'holding:'.$holding->id : 'listing:'.$holding->market_listing_id;
                    $requests[$key]['holding'] = $holding;
                    $requests[$key]['dates'][] = $this->prices->tradingDate($holding, $cutoff);
                }
            }
        }

        $failed = false;
        try {
            foreach ($requests as $request) {
                $result = $this->daily->import($request['holding'], tradingDates: array_values(array_unique($request['dates'])));
                $failed = $failed || in_array($result['status'], ['failed', 'cooldown'], true);
            }
        } finally {
            $this->snapshots->retryIncomplete($depot, $run['dates'], $run['completed_period']);
        }

        $this->finish($depotId, $runId, $failed ? 'failed' : 'finished');
    }

    public function finish(int $depotId, string $runId, string $status): void
    {
        $run = $this->find($depotId, $runId);
        if ($run !== null) {
            Cache::put($this->key($depotId, $runId), [...$run, 'status' => $status], now()->addMinutes(15));
        }
    }

    private function key(int $depotId, ?string $runId = null): string
    {
        return 'dashboard.performance.repair.'.$depotId.($runId === null ? '' : '.'.$runId);
    }
}
