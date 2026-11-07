<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\XetraIntradayCollector;
use App\Services\YahooFinanceMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntradayMarketIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_tradegate_rows_are_preserved_but_excluded_from_xetra_relations_and_price_selection(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'BGCZPX003174', 'symbol' => 'CZX', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        foreach (['XGAT', 'XETA'] as $index => $mic) {
            StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-02',
                'interval' => 'trade', 'as_of' => '2026-11-02 10:00:0'.$index, 'close' => 10 + $index,
                'currency' => 'EUR', 'source_key' => 'deutsche_boerse_trade', 'raw_payload' => ['mic' => $mic]]);
        }
        $wrong = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin,
            'currency' => 'EUR', 'mic' => 'XGAT', 'source_key' => 'deutsche_boerse_trade']);
        $holding->update(['latest_realtime_price_id' => $wrong->id]);

        $this->assertCount(1, $holding->intradayCandles()->get());
        $this->assertSame('XETA', $holding->intradayCandles()->firstOrFail()->raw_payload['mic']);
        $this->assertNull($holding->fresh()->latestRealtimePrice);
        $this->assertSame(2, StockHoldingIntradayCandle::withoutGlobalScopes()->count());
        $this->assertTrue(StockRealtimePrice::withoutGlobalScopes()->whereKey($wrong->id)->exists());
    }

    public function test_wrong_currency_and_quarantined_rows_are_not_used_but_legacy_eodhd_is_untouched(): void
    {
        $holding = StockHolding::factory()->create(['exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        foreach ([['USD', 'xetra_posttrade', ['mic' => 'XETA']], ['EUR', 'xetra_posttrade', ['mic' => 'XETA', 'quarantined' => true]],
            ['EUR', 'eodhd_intraday', []]] as $index => [$currency, $source, $raw]) {
            StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-02',
                'interval' => '5m', 'as_of' => '2026-11-02 10:00:0'.$index, 'close' => 10, 'currency' => $currency,
                'source_key' => $source, 'raw_payload' => $raw]);
        }
        $this->assertSame(['eodhd_intraday'], $holding->intradayCandles()->pluck('source_key')->all());
        $this->assertSame(3, StockHoldingIntradayCandle::withoutGlobalScopes()->count());
    }

    public function test_yahoo_xetra_requires_the_exact_verified_isin_ticker_market_and_currency(): void
    {
        $identity = ['isin' => 'BGCZPX003174', 'symbol' => 'CZX', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR'];
        $this->assertTrue(YahooFinanceMarketData::supportsXetra($identity));
        foreach ([['isin' => 'IE00B53L4X51'], ['symbol' => 'CZXG'], ['currency' => 'USD'], ['exchange' => 'F', 'mic_code' => 'XFRA']] as $change) {
            $this->assertFalse(YahooFinanceMarketData::supportsXetra([...$identity, ...$change]));
        }
    }

    public function test_verified_yahoo_xetra_ohlc_are_kept_separate_from_actual_trades(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 12:00', 'UTC'));
        Http::preventStrayRequests();
        $holding = StockHolding::factory()->create(['isin' => 'BGCZPX003174', 'symbol' => 'CZX', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        Http::fake(['query1.finance.yahoo.com/v8/finance/chart/CZX.DE*' => Http::response(['chart' => ['result' => [[
            'meta' => ['symbol' => 'CZX.DE', 'currency' => 'EUR', 'exchangeName' => 'GER', 'fullExchangeName' => 'XETRA',
                'exchangeTimezoneName' => 'Europe/Berlin', 'instrumentType' => 'ETF', 'regularMarketPrice' => 10,
                'regularMarketTime' => now()->subHour()->timestamp, 'dataGranularity' => '5m'],
            'timestamp' => [Carbon::parse('2026-11-02 10:00', 'UTC')->timestamp],
            'indicators' => ['quote' => [['open' => [9], 'high' => [11], 'low' => [8], 'close' => [10], 'volume' => [5]]]],
        ]]]])]);
        $result = app(YahooFinanceMarketData::class)->runXetra($holding->toArray());
        $bar = $result['instruments'][0]['series'][0];
        $this->assertSame('provider_ohlc', $bar['data_kind']);
        $this->assertSame('yahoo_xetra_intraday', $bar['source_key']);
        $this->assertSame('XETR', $bar['mic']);
        $this->assertSame('EUR', $bar['currency']);
        $this->assertFalse($bar['coverage_complete']);
        Http::assertSentCount(1);
    }

    public function test_intraday_status_exposes_pending_files_and_excluded_legacy_rows_without_external_requests(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $holding = StockHolding::factory()->create(['isin' => 'BGCZPX003174', 'symbol' => 'CZX', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-02', 'interval' => 'trade',
            'as_of' => '2026-11-02 10:00:00', 'close' => 10, 'source_key' => 'deutsche_boerse_trade', 'currency' => 'EUR', 'raw_payload' => ['mic' => 'XGAT']]);
        AppConfig::query()->create(['key' => XetraIntradayCollector::ReportKey, 'value' => ['checked_at' => '2026-11-02T12:00:00Z', 'pending_files' => 1,
            'instruments' => [['isin' => $holding->isin, 'symbol' => 'CZX', 'status' => 'incomplete', 'coverage_complete' => false]], 'days' => []]]);
        Http::preventStrayRequests();
        $this->actingAs($user)->getJson('/admin/data/intraday?stock='.$holding->id)->assertOk()
            ->assertJsonPath('free_intraday.excluded_legacy_rows', 1)
            ->assertJsonPath('free_intraday.pending_files', 1)->assertJsonPath('free_intraday.instruments.0.status', 'incomplete');
        Http::assertNothingSent();
        $this->assertSame(0, $holding->intradayCandles()->count());
        $this->assertSame(1, StockHoldingIntradayCandle::withoutGlobalScopes()->count());
    }
}
