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
    private const MaxProviderWaits = 2;

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
            if (in_array($previous['status'] ?? null, ['queued', 'running', 'waiting'], true)) {
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

    public function process(int $depotId, string $runId, bool $canWait): ?int
    {
        $run = $this->find($depotId, $runId);
        if (! in_array($run['status'] ?? null, ['queued', 'waiting'], true)) {
            return null;
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

        if (isset($run['pending_symbols'])) {
            $requests = array_intersect_key($requests, array_flip($run['pending_symbols']));
        }
        $pending = $requests;
        $failure = $run['previous_failure'] ?? [];
        $blocked = [];
        $pause = [];
        try {
            foreach ($requests as $key => $request) {
                $result = $this->daily->import($request['holding'], tradingDates: array_values(array_unique($request['dates'])), prioritizeRepair: $canWait);
                $reason = $result['pause_reason'] ?? null;
                if ($reason === 'symbol') {
                    $blocked[$key] = $result['retry_at'];

                    continue;
                }
                if ($reason !== null) {
                    $pause = ['pause_reason' => $reason, 'retry_at' => $result['retry_at'] ?? null,
                        'provider_http_status' => $result['provider_http_status'] ?? null];
                    break;
                }
                if ($result['status'] === 'failed' && $failure === []) {
                    $failure = ['failure_reason' => $result['failure_reason'], 'provider_http_status' => $result['provider_http_status']];
                }
                unset($pending[$key]);
            }
        } finally {
            $this->snapshots->retryIncomplete($depot, $run['dates'], $run['completed_period']);
        }

        if ($pause === [] && $blocked !== []) {
            $retryAt = in_array(null, $blocked, true) ? null : max($blocked);
            $pause = ['pause_reason' => 'symbol', 'retry_at' => $retryAt];
        }

        $providerWaits = $run['provider_waits'] ?? 0;
        $reason = $pause['pause_reason'] ?? null;
        $retryAt = $pause['retry_at'] ?? null;
        $canWaitForProvider = $reason === 'provider' && $retryAt !== null && $providerWaits < self::MaxProviderWaits;
        if ($canWait && ($reason === 'symbol' || $canWaitForProvider)) {
            $this->finish($depotId, $runId, 'waiting', [
                ...$pause,
                'pending_symbols' => array_keys($pending),
                'previous_failure' => $failure,
                'provider_waits' => $providerWaits + (int) $canWaitForProvider,
            ]);

            return $retryAt === null ? MarketDataCoordinator::PollSeconds : max(1, $retryAt - now()->timestamp);
        }

        $this->finish($depotId, $runId, $pause !== [] ? 'paused' : ($failure !== [] ? 'failed' : 'finished'), $pause ?: $failure);

        return null;
    }

    /** @param array<string, mixed> $details */
    public function finish(int $depotId, string $runId, string $status, array $details = []): void
    {
        $run = $this->find($depotId, $runId);
        if ($run !== null) {
            $expiresAt = $status === 'waiting' && isset($details['retry_at'])
                ? Carbon::createFromTimestamp($details['retry_at'])->addMinutes(15)
                : now()->addMinutes(15);
            $activeKey = $this->key($depotId);
            if (in_array($status, ['running', 'waiting'], true) && Cache::get($activeKey) === $runId) {
                Cache::put($activeKey, $runId, $expiresAt);
            }
            Cache::put($this->key($depotId, $runId), [...$run, ...$details, 'status' => $status], $expiresAt);
        }
    }

    private function key(int $depotId, ?string $runId = null): string
    {
        return 'dashboard.performance.repair.'.$depotId.($runId === null ? '' : '.'.$runId);
    }
}
