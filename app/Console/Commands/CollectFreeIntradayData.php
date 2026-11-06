<?php

namespace App\Console\Commands;

use App\Jobs\CollectFreeIntradayData as CollectJob;
use App\Models\StockHolding;
use App\Services\FreeIntradayMarketData;
use App\Services\MarketPriceStore;
use App\Services\PreviewIsolation;
use App\Services\YahooFinanceMarketData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intraday:collect-free {--queue : Dispatch a resumable collector job} {--batches=1 : Maximum bounded batches to process} {--json : Emit coverage and per-instrument diagnostics} {--verify-yahoo : Check explicitly verified Xetra mappings against Yahoo}')]
#[Description('Collect free venue-bound intraday data and report verified file coverage')]
class CollectFreeIntradayData extends Command
{
    public function handle(FreeIntradayMarketData $marketData, YahooFinanceMarketData $yahoo): int
    {
        if (MarketPriceStore::enabled()) {
            return $this->call('market-data:collect');
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        if ($this->option('queue')) {
            CollectJob::dispatch();

            return self::SUCCESS;
        }
        $batches = max(1, min(120, (int) $this->option('batches')));
        do {
            $coverage = $marketData->xetraCoverage(advance: true);
            if (! $this->option('json')) {
                $this->info('Pending Xetra files: '.$coverage['pending_files']);
            }
        } while (--$batches > 0 && ($coverage['retryable_files'] ?? $coverage['pending_files']) > 0 && $coverage['warnings'] === []);

        if ($coverage['collector_busy'] ?? false) {
            $this->line($this->option('json') ? json_encode($coverage, JSON_THROW_ON_ERROR) : implode(' ', $coverage['warnings']));

            return self::FAILURE;
        }
        $result = $marketData->syncAll();
        $result['xetra_files'] = array_diff_key($coverage, ['series' => true, 'instruments' => true]);
        if ($this->option('verify-yahoo')) {
            $result['yahoo_checks'] = [];
            foreach (StockHolding::query()->orderBy('id')->get() as $holding) {
                if (! YahooFinanceMarketData::supportsXetra($holding->toArray())) {
                    continue;
                }
                $check = $yahoo->runXetra($holding->toArray());
                foreach ($check['instruments'] as &$row) {
                    $row['ohlc_count'] = count($row['series'] ?? []);
                    unset($row['series']);
                }
                unset($row);
                $result['yahoo_checks'][$holding->isin] = $check;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info($result['message']);
            foreach ($result['coverage'] as $instrument) {
                $this->line($instrument['symbol'].' '.$instrument['status'].' · '.($instrument['trade_at'] ?? 'no observed trade'));
            }
        }

        return $coverage['pending_files'] === 0 && $result['failed_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
