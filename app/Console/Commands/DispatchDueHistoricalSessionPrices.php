<?php

namespace App\Console\Commands;

use App\Services\HistoricalSessionPriceScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('historical-session-prices:dispatch-due')]
#[Description('Dispatch due historical session price bundle jobs by exchange')]
class DispatchDueHistoricalSessionPrices extends Command
{
    public function handle(HistoricalSessionPriceScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Dispatched {$dispatchedCount} historical session price job(s).");

        return self::SUCCESS;
    }
}
