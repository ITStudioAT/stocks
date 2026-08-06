<?php

namespace App\Console\Commands;

use App\Services\V2IndexRealtimeScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('indices:v2-realtime:dispatch-due')]
#[Description('Queue due v2 index realtime synchronizations')]
class DispatchDueV2IndexRealtimeSyncs extends Command
{
    public function handle(V2IndexRealtimeScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Queued {$dispatchedCount} v2 index realtime synchronization(s).");

        return self::SUCCESS;
    }
}
