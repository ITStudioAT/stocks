<?php

namespace App\Console\Commands;

use App\Services\V2IndexEodhdSyncScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('indices:eodhd-sync:dispatch-due')]
#[Description('Queue due v2 index EODHD synchronizations using their independent schedule')]
class DispatchDueV2IndexEodhdSyncs extends Command
{
    public function handle(V2IndexEodhdSyncScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Queued {$dispatchedCount} v2 index EODHD synchronization(s).");

        return self::SUCCESS;
    }
}
