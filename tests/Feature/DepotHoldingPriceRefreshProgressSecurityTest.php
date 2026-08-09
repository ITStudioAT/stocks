<?php

namespace Tests\Feature;

use App\Models\StockPriceRefreshRun;
use App\Services\DepotHoldingPriceRefreshProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DepotHoldingPriceRefreshProgressSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_redacts_eodhd_tokens_from_cached_and_stored_progress_errors(): void
    {
        config(['services.eodhd.key' => 'configured-progress-secret']);

        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('cached-progress', 1);
        $cached = $progress->fail(
            'cached-progress',
            'Request failed: api_token=configured-progress-secret&fmt=json',
        );

        $this->assertStringNotContainsString('configured-progress-secret', $cached['error']);
        $this->assertStringContainsString('api_token=[redacted]', $cached['error']);

        Cache::put('depot-holding-price-refresh:legacy-progress', [
            'refresh_id' => 'legacy-progress',
            'status' => 'failed',
            'processed' => 0,
            'total' => 1,
            'step' => '0/1',
            'message' => 'Price refresh failed.',
            'current' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'error' => 'Request failed: api_token=rotated-cache-secret',
        ], now()->addHour());

        $this->assertSame(
            'Request failed: api_token=[redacted]',
            $progress->get('legacy-progress')['error'],
        );

        $run = StockPriceRefreshRun::query()->create([
            'id' => 'stored-progress',
            'status' => 'failed',
            'total_count' => 1,
            'processed_count' => 0,
            'error_summary' => [
                'message' => 'Request failed: apiToken=rotated-database-secret',
            ],
            'finished_at' => now(),
        ]);

        $stored = $progress->getStored($run->id);

        $this->assertStringNotContainsString('rotated-database-secret', $stored['error']);
        $this->assertStringContainsString('apiToken=[redacted]', $stored['error']);
    }
}
