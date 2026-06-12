<?php

namespace App\Console\Commands;

use App\Services\IntradayCandleBackfillScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intraday-candles:dispatch-due')]
#[Description('Dispatch the due missing one-year intraday candle backfill job')]
class DispatchDueIntradayCandleBackfills extends Command
{
    public function handle(IntradayCandleBackfillScheduler $scheduler): int
    {
        $dispatchedCount = $scheduler->dispatchDue();

        $this->info("Dispatched {$dispatchedCount} intraday candle backfill job(s).");

        return self::SUCCESS;
    }
}
