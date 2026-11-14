<?php

namespace App\Jobs;

use App\Models\AppConfig;
use App\Models\Depot;
use App\Models\StockHolding;
use App\Services\DashboardPerformanceSnapshots;
use App\Services\FreeDailyMarketData;
use App\Services\MarketPriceStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckPerformanceConsistency implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const Key = 'dashboard_performance_consistency';

    public int $timeout = 7200;

    public int $tries = 1;

    public int $uniqueFor = 7500;

    public function __construct(public string $runId) {}

    public function uniqueId(): string
    {
        return self::Key;
    }

    public function handle(DashboardPerformanceSnapshots $snapshots): void
    {
        $run = $this->run();
        if (($run['id'] ?? null) !== $this->runId) {
            return;
        }
        $run['issues'] ??= [];
        $run['periods'] ??= [];
        $run['status'] = 'running';
        if (MarketPriceStore::enabled()) {
            $this->checkDailyHistory($run, app(FreeDailyMarketData::class));
        }
        $run['step'] = 'Depots werden geprüft';
        $this->save($run);

        foreach (Depot::query()->orderBy('id')->get() as $depot) {
            if ($run['repair']) {
                DB::transaction(function () use ($depot): void {
                    Depot::query()->lockForUpdate()->findOrFail($depot->id);
                    DB::table(DashboardPerformanceSnapshots::Table)->where('depot_id', $depot->id)->where('period', 'day')->delete();
                }, attempts: 3);
            }
            $first = $depot->transactions()->min('booked_at');
            $firstDate = $first === null ? $depot->created_at : Carbon::parse($first);
            if ($firstDate === null) {
                continue;
            }
            foreach (['week' => 'Wochen', 'month' => 'Monate', 'year' => 'Jahre'] as $period => $label) {
                $run['periods'][$period] ??= ['checked' => 0, 'missing' => 0, 'incorrect' => 0, 'unavailable' => 0];
                $run['step'] = "{$depot->name}: {$label} werden geprüft";
                $this->save($run);
                $end = $snapshots->periodEnd($firstDate, $period);
                while ($end->lt(now()->startOfDay())) {
                    DB::transaction(function () use ($depot, $period, $end, $snapshots, &$run): void {
                        $locked = Depot::query()->lockForUpdate()->findOrFail($depot->id);
                        $key = ['depot_id' => $depot->id, 'period' => $period, 'end_date' => $end->toDateString()];
                        $row = DB::table(DashboardPerformanceSnapshots::Table)->where($key)->first();
                        $actual = $row ? json_decode($row->payload, true) : null;
                        $fresh = $snapshots->calculatePeriodFresh($locked, $period, $end);
                        $missing = $row === null || ($row->expires_at !== null && Carbon::parse($row->expires_at)->isPast())
                            || ! is_array($actual) || ($actual['_source_policy'] ?? null) !== $snapshots->sourcePolicy();
                        if (is_array($actual)) {
                            unset($actual['_source_policy']);
                            ksort($actual);
                        }
                        $expected = $fresh;
                        ksort($expected);
                        $incorrect = ! $missing && $actual !== $expected;
                        $run['checked']++;
                        $run['missing'] += (int) $missing;
                        $run['incorrect'] += (int) $incorrect;
                        $run['unavailable'] += (int) ($fresh['change_amount'] === null);
                        $run['periods'][$period]['checked']++;
                        $run['periods'][$period]['missing'] += (int) $missing;
                        $run['periods'][$period]['incorrect'] += (int) $incorrect;
                        $run['periods'][$period]['unavailable'] += (int) ($fresh['change_amount'] === null);
                        if (($missing || $incorrect || $fresh['change_amount'] === null) && count($run['issues']) < 100) {
                            $differences = [];
                            foreach (['change_amount' => 'Betrag', 'change_percent' => 'Prozentwert', 'source_markers' => 'Quellenangabe'] as $field => $description) {
                                if (! is_array($actual) || ! array_key_exists($field, $actual) || $actual[$field] !== $fresh[$field]) {
                                    $differences[] = $description;
                                }
                            }
                            $run['issues'][] = [
                                'depot' => $locked->name,
                                'period' => $period,
                                'end_date' => $end->toDateString(),
                                'reason' => $missing ? 'missing' : ($incorrect ? 'incorrect' : 'unavailable'),
                                'saved_amount' => $actual['change_amount'] ?? null,
                                'calculated_amount' => $fresh['change_amount'],
                                'saved_percent' => $actual['change_percent'] ?? null,
                                'calculated_percent' => $fresh['change_percent'],
                                'differences' => $differences,
                            ];
                        }
                        if ($run['repair'] && ($missing || $incorrect) && $fresh['change_amount'] === null) {
                            DB::table(DashboardPerformanceSnapshots::Table)->where($key)->delete();
                        }
                        if ($run['repair'] && ($missing || $incorrect) && $fresh['change_amount'] !== null) {
                            DB::table(DashboardPerformanceSnapshots::Table)->upsert([...$key, 'payload' => json_encode([...$fresh, '_source_policy' => $snapshots->sourcePolicy()], JSON_THROW_ON_ERROR), 'expires_at' => $fresh['change_amount'] === null ? now()->addHour() : null], ['depot_id', 'period', 'end_date'], ['payload', 'expires_at']);
                            $run['repaired']++;
                        }
                    }, attempts: 3);
                    $this->save($run);
                    $end = $snapshots->periodEnd($end->copy()->addDay(), $period);
                }
                $run['steps'][] = "{$depot->name}: {$label} geprüft";
                $this->save($run);
            }
        }
        $run['status'] = 'finished';
        $run['step'] = 'Prüfung abgeschlossen';
        $run['finished_at'] = now()->toIso8601String();
        $this->save($run);
    }

    private function checkDailyHistory(array &$run, FreeDailyMarketData $daily): void
    {
        $run['daily_history'] = [];
        foreach (StockHolding::query()->orderBy('id')->lazyById() as $holding) {
            $label = $holding->name ?? $holding->symbol;
            $run['step'] = "{$label}: Jahreskurse werden geprüft";
            $this->save($run);
            $before = $daily->coverage($holding);
            $after = $before;
            $repairStatus = null;
            if ($run['repair'] && $before['status'] === 'missing') {
                $run['step'] = "{$label}: Fehlende Tageskurse werden nachgeladen";
                $this->save($run);
                $repairStatus = $daily->import($holding)['status'];
                $after = $daily->coverage($holding);
            }

            $run['daily_history'][] = [
                'holding_id' => $holding->id,
                'label' => $label,
                'symbol' => $holding->symbol,
                'exchange' => $holding->exchange,
                ...$after,
                'filled_count' => max(0, ($before['missing_count'] ?? 0) - ($after['missing_count'] ?? 0)),
                'repair_status' => $repairStatus,
            ];
            $this->save($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->run();
        if (($run['id'] ?? null) === $this->runId) {
            $run['status'] = 'failed';
            $run['step'] = 'Prüfung fehlgeschlagen';
            $this->save($run);
        }
    }

    private function run(): array
    {
        return AppConfig::query()->where('key', self::Key)->first()?->value ?? [];
    }

    private function save(array $run): void
    {
        AppConfig::query()->where('key', self::Key)->update(['value' => json_encode($run, JSON_THROW_ON_ERROR)]);
    }
}
