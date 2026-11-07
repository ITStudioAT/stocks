<?php

namespace App\Jobs;

use App\Services\DashboardPerformanceRepair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

class RepairDashboardPerformance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(public int $depotId, public string $runId) {}

    public function handle(DashboardPerformanceRepair $repair): void
    {
        $canWait = $this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries;
        $delay = $repair->process($this->depotId, $this->runId, $canWait);
        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DashboardPerformanceRepair::class)->finish($this->depotId, $this->runId, 'failed');
    }
}
