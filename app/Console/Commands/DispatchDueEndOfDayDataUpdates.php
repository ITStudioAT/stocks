<?php

namespace App\Console\Commands;

use App\Services\EndOfDayDataUpdateScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('end-of-day-data:dispatch-due')]
#[Description('Run due end-of-day EODHD updates on the shared schedule')]
class DispatchDueEndOfDayDataUpdates extends Command
{
    public function handle(EndOfDayDataUpdateScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Ran {$dispatchedCount} end-of-day data update(s).");

        return self::SUCCESS;
    }
}
