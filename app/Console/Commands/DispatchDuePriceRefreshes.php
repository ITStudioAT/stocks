<?php

namespace App\Console\Commands;

use App\Services\PriceRefreshScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('price-refresh:dispatch-due')]
#[Description('Run due stock realtime syncs for the watch-list on the shared schedule')]
class DispatchDuePriceRefreshes extends Command
{
    public function handle(
        PriceRefreshScheduler $scheduler,
    ): int {
        $dispatchedCount = $scheduler->dispatchDueRefreshes();

        $this->info("Queued {$dispatchedCount} watch-list realtime sync(s).");

        return self::SUCCESS;
    }
}
