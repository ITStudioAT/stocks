<?php

namespace App\Console\Commands;

use App\Services\IndexDataUpdateScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('indices-data:dispatch-due')]
#[Description('Run due weekly index data updates on the shared schedule')]
class DispatchDueIndexDataUpdates extends Command
{
    public function handle(IndexDataUpdateScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Ran {$dispatchedCount} indices data update(s).");

        return self::SUCCESS;
    }
}
