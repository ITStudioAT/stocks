<?php

namespace App\Console\Commands;

use App\Services\HistoricalSessionPriceScheduler;
use App\Services\IndexPriceRefreshSettings;
use App\Services\PriceRefreshScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('price-refresh:dispatch-due')]
#[Description('Dispatch the due stock price refresh job for the watch-list on the shared schedule')]
class DispatchDuePriceRefreshes extends Command
{
    public function handle(
        PriceRefreshScheduler $scheduler,
        IndexPriceRefreshSettings $indexPriceRefreshSettings,
        HistoricalSessionPriceScheduler $historicalSessionPriceScheduler,
    ): int {
        $dispatchedCount = $scheduler->dispatchDueRefreshes();
        $dispatchedIndexCount = $indexPriceRefreshSettings->dispatchDueRefreshes();
        $dispatchedHistoricalSessionCount = $historicalSessionPriceScheduler->dispatchDue();

        $this->info("Dispatched {$dispatchedCount} watch-list price refresh job(s).");
        $this->info("Refreshed {$dispatchedIndexCount} due index price schedule(s).");
        $this->info("Dispatched {$dispatchedHistoricalSessionCount} historical session price job(s).");

        return self::SUCCESS;
    }
}
