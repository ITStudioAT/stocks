<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Services\EodhdHistoricalDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EodhdHistoricalDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_from_the_next_day_after_the_latest_visible_eod_date_until_the_scheduled_target_date(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 01:00:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding();
        StockPrice::factory()->create([
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'AMES',
            'price' => '100.00000000',
            'close' => '100.00000000',
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse('2026-06-25 23:59:59', 'Europe/Vienna')->utc(),
            'fetched_at' => Carbon::parse('2026-06-25 23:59:59', 'Europe/Vienna')->utc(),
            'freshness_status' => 'historical',
        ]);

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([
                [
                    'date' => '2026-06-26',
                    'open' => 100.25,
                    'high' => 102.00,
                    'low' => 99.75,
                    'close' => 101.50,
                    'volume' => 12345,
                    'api_token' => 'provider-echoed-historical-secret',
                ],
            ]),
        ]);

        $result = app(EodhdHistoricalDataService::class)
            ->syncAll(Carbon::parse('2026-06-26', 'Europe/Vienna'));

        $this->assertSame(1, $result['requested_count']);
        $this->assertSame(1, $result['stored_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(0, $result['failed_count']);
        $this->assertSame('2026-06-26', $result['target_date']);
        $this->assertDatabaseHas('stock_prices', [
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'AMES',
            'price' => '101.50000000',
            'close' => '101.50000000',
            'price_type' => 'historical_eod',
            'as_of' => '2026-06-26 21:59:59',
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
        $this->assertDatabaseHas('stock_holding_daily_prices', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-26',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'close' => '101.50000000',
            'currency' => 'EUR',
        ]);
        $stockPayload = StockPrice::query()
            ->where('source_key', 'eodhd_eod')
            ->where('as_of', '2026-06-26 21:59:59')
            ->sole()
            ->raw_payload;
        $dailyPayload = StockHoldingDailyPrice::query()->sole()->raw_payload;
        $this->assertSame('[redacted]', $stockPayload['api_token']);
        $this->assertSame('[redacted]', $dailyPayload['api_token']);
        $this->assertStringNotContainsString(
            'provider-echoed-historical-secret',
            json_encode([$stockPayload, $dailyPayload], JSON_THROW_ON_ERROR),
        );
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AMES.XETRA')
            && $request['from'] === '2026-06-26'
            && $request['to'] === '2026-06-26'
            && $request['period'] === 'd'
            && $request['fmt'] === 'json');
    }

    public function test_it_does_not_call_eodhd_when_the_latest_visible_eod_date_reaches_the_target_date(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 01:00:00', 'Europe/Vienna'));
        $this->xetraHolding();
        StockPrice::factory()->create([
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'tradegate',
            'source_name' => 'Tradegate Exchange',
            'symbol' => 'AMES',
            'price' => '101.50000000',
            'price_type' => 'last',
            'as_of' => Carbon::parse('2026-06-26 23:59:59', 'Europe/Vienna')->utc(),
            'fetched_at' => Carbon::parse('2026-06-26 23:59:59', 'Europe/Vienna')->utc(),
        ]);

        Http::fake(fn () => throw new RuntimeException('Historical API should not be called.'));

        $result = app(EodhdHistoricalDataService::class)
            ->syncAll(Carbon::parse('2026-06-26', 'Europe/Vienna'));

        $this->assertSame(0, $result['requested_count']);
        $this->assertSame(0, $result['stored_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(0, $result['failed_count']);
        Http::assertNothingSent();
    }

    private function xetraHolding(): StockHolding
    {
        return StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
    }
}
