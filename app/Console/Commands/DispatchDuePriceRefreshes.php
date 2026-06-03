<?php

namespace App\Console\Commands;

use App\Services\PriceRefreshScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('price-refresh:dispatch-due')]
#[Description('Dispatch due stock price refresh jobs for every depot on the shared schedule')]
class DispatchDuePriceRefreshes extends Command
{
    public function handle(PriceRefreshScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDueRefreshes();

        $this->info("Dispatched {$dispatchedCount} depot price refresh job(s).");

        return self::SUCCESS;
    }
}
