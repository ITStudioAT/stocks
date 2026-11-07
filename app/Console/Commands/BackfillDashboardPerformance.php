<?php

namespace App\Console\Commands;

use App\Models\Depot;
use App\Services\DashboardPerformanceSnapshots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dashboard:backfill-performance {--rebuild : Recalculate stored periods after a historical correction}')]
#[Description('Store completed weekly, monthly and yearly dashboard performance')]
class BackfillDashboardPerformance extends Command
{
    public function handle(DashboardPerformanceSnapshots $snapshots): int
    {
        Depot::query()->eachById(function (Depot $depot) use ($snapshots): void {
            if ($this->option('rebuild')) {
                $snapshots->invalidate($depot->id);
            }

            $snapshots->backfill($depot);
        });

        $this->info('Completed dashboard periods are stored.');

        return self::SUCCESS;
    }
}
