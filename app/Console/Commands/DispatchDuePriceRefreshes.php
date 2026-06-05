<?php

namespace App\Console\Commands;

use App\Services\IndexPriceRefreshSettings;
use App\Services\PriceRefreshScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('price-refresh:dispatch-due')]
#[Description('Dispatch the due stock price refresh job for the watch-list on the shared schedule')]
class DispatchDuePriceRefreshes extends Command
{
    public function handle(PriceRefreshScheduler $scheduler, IndexPriceRefreshSettings $indexPriceRefreshSettings): int
    {
        $dispatchedCount = $scheduler->dispatchDueRefreshes();
        $dispatchedIndexCount = $indexPriceRefreshSettings->dispatchDueRefreshes();

        $this->info("Dispatched {$dispatchedCount} watch-list price refresh job(s).");
        $this->info("Refreshed {$dispatchedIndexCount} due index price schedule(s).");

        return self::SUCCESS;
    }
}
