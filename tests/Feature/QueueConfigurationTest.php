<?php

namespace Tests\Feature;

use App\Jobs\FetchHistoricalSessionPrices;
use App\Jobs\FetchStockHistoricalPrices;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Jobs\ReloadEodhdExchanges;
use App\Jobs\ReloadStockHoldingIntradayData;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_async_queue_retry_after_values_exceed_long_running_job_timeouts(): void
    {
        $longestJobTimeout = max([
            (new RefreshDepotHoldingPrices('test-refresh'))->timeout,
            (new FetchStockHistoricalPrices('test-refresh'))->timeout,
            (new ReloadEodhdExchanges('test-refresh'))->timeout,
            (new ReloadStockHoldingIntradayData('test-refresh'))->timeout,
            (new FetchHistoricalSessionPrices('XETRA', [], [
                'timezone' => 'Europe/Berlin',
                'today_date' => '2026-06-05',
                'today_open' => '2026-06-05T09:00:00+02:00',
                'today_close' => '2026-06-05T17:30:00+02:00',
                'previous_date' => '2026-06-04',
                'previous_open' => '2026-06-04T09:00:00+02:00',
                'previous_close' => '2026-06-04T17:30:00+02:00',
                'two_ago_date' => '2026-06-03',
                'two_ago_open' => '2026-06-03T09:00:00+02:00',
                'two_ago_close' => '2026-06-03T17:30:00+02:00',
            ]))->timeout,
        ]);

        foreach (['database', 'redis'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan($longestJobTimeout, $retryAfter, "{$connection} retry_after must exceed the longest queued job timeout.");
        }
    }
}
