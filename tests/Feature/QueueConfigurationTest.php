<?php

namespace Tests\Feature;

use App\Jobs\BackfillMissingStockHoldingIntradayCandles;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use App\Jobs\SyncStockEodhdData;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_async_queue_retry_after_values_exceed_long_running_job_timeouts(): void
    {
        $longestJobTimeout = max([
            (new RefreshDepotHoldingPrices('test-refresh'))->timeout,
            (new BackfillMissingStockHoldingIntradayCandles('test-refresh'))->timeout,
            (new ReloadEodhdExchanges('test-refresh'))->timeout,
            (new ReloadStockHoldingIntradayData('test-refresh'))->timeout,
            (new SyncStockEodhdData('test-refresh'))->timeout,
        ]);

        foreach (['database', 'redis'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan($longestJobTimeout, $retryAfter, "{$connection} retry_after must exceed the longest queued job timeout.");
        }
    }
}
