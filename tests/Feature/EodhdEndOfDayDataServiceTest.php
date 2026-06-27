<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\EodhdEndOfDayDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EodhdEndOfDayDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_and_stores_only_missing_stock_price_dates(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-27 01:00:00', 'Europe/Vienna'));
        $holding = $this->xetraHolding();
        $this->createHistoricalStockPrice($holding, '2026-06-24', '100.00000000');
        $this->createHistoricalStockPrice($holding, '2026-06-26', '102.00000000');

        Http::fake([
            'eodhd.com/api/eod/AMES.XETRA*' => Http::response([
                [
                    'date' => '2026-06-25',
                    'open' => 100.25,
                    'high' => 102.00,
                    'low' => 99.75,
                    'close' => 101.50,
                    'adjusted_close' => 101.40,
                    'volume' => 12345,
                ],
            ]),
        ]);

        $result = app(EodhdEndOfDayDataService::class)->syncHolding(
            $holding,
            Carbon::parse('2026-06-24', 'Europe/Vienna'),
            Carbon::parse('2026-06-26', 'Europe/Vienna'),
        );

        $this->assertSame(1, $result['requested_count']);
        $this->assertSame(1, $result['stored_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame([['from' => '2026-06-25', 'to' => '2026-06-25']], $result['ranges']);
        $this->assertDatabaseHas('stock_prices', [
            'instrument_key' => 'isin:FR0010655746',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'AMES',
            'price' => '101.50000000',
            'close' => '101.50000000',
            'price_type' => 'historical_eod',
            'as_of' => '2026-06-25 21:59:59',
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
        $this->assertSame(3, StockPrice::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AMES.XETRA')
            && $request['from'] === '2026-06-25'
            && $request['to'] === '2026-06-25'
            && $request['period'] === 'd'
            && $request['fmt'] === 'json');
    }

    public function test_it_skips_eodhd_when_all_stock_price_dates_are_stored(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $holding = $this->xetraHolding();
        $this->createHistoricalStockPrice($holding, '2026-06-24', '100.00000000');
        $this->createHistoricalStockPrice($holding, '2026-06-25', '101.00000000');
        $this->createHistoricalStockPrice($holding, '2026-06-26', '102.00000000');

        Http::fake(fn () => throw new RuntimeException('EODHD should not be called.'));

        $result = app(EodhdEndOfDayDataService::class)->syncHolding(
            $holding,
            Carbon::parse('2026-06-24', 'Europe/Vienna'),
            Carbon::parse('2026-06-26', 'Europe/Vienna'),
        );

        $this->assertSame(0, $result['requested_count']);
        $this->assertSame(0, $result['stored_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame([], $result['ranges']);
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

    private function createHistoricalStockPrice(StockHolding $holding, string $date, string $price): StockPrice
    {
        return StockPrice::factory()->create([
            'instrument_key' => 'isin:'.$holding->isin,
            'quote_hash' => hash('sha256', "{$holding->isin}|{$date}|{$price}"),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'close' => $price,
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse($date, 'Europe/Vienna')->endOfDay()->utc(),
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
    }
}
