<?php

namespace App\Jobs;

use App\Services\DashboardPerformanceRepair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RepairDashboardPerformance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $depotId, public string $runId) {}

    public function handle(DashboardPerformanceRepair $repair): void
    {
        $repair->process($this->depotId, $this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(DashboardPerformanceRepair::class)->finish($this->depotId, $this->runId, 'failed');
    }
}
