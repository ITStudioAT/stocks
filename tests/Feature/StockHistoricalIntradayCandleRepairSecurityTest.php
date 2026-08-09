<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Services\EodhdMarketData;
use App\Services\StockHistoricalIntradayCandleRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class StockHistoricalIntradayCandleRepairSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_redacts_eodhd_tokens_from_provider_errors(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 18:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'configured-repair-secret']);
        Http::fake([
            'eodhd.com/api/intraday/*' => Http::response([
                'status' => 'error',
                'message' => 'Failed URL: api_token=configured-repair-secret&fmt=json',
            ]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'exchange' => 'US',
        ]);
        $this->mock(EodhdMarketData::class)
            ->expects('exchangeCodeForHolding')
            ->once()
            ->with($holding)
            ->andReturn('US');

        try {
            app(StockHistoricalIntradayCandleRepairService::class)->repairHolding($holding);
            $this->fail('Expected the EODHD repair to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('configured-repair-secret', $exception->getMessage());
            $this->assertStringContainsString('api_token=[redacted]', $exception->getMessage());
        }
    }
}
