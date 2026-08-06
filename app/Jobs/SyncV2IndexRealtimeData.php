<?php

namespace App\Jobs;

use App\Services\V2IndexRealtimeScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncV2IndexRealtimeData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 660;

    public function uniqueId(): string
    {
        return 'eodhd-v2-indices-realtime-sync';
    }

    public function handle(V2IndexRealtimeScheduler $scheduler): void
    {
        $scheduler->syncNow();
    }

    public function failed(?Throwable $exception): void
    {
        app(V2IndexRealtimeScheduler::class)->markFailed(
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
