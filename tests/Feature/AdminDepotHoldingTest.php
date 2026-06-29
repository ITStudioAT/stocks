<?php

namespace Tests\Feature;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\EodhdExchange;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockHoldingIntradayPrice;
use App\Models\StockPrice;
use App\Models\StockPriceRefreshRun;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\EodhdBatchRealtimePriceService;
use App\Services\StockPriceCatalog;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDepotHoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_watchlist_holdings_with_pagination(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->count(12)->create();

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?page=2')
            ->assertOk()
            ->assertJsonPath('depot', null)
            ->assertJsonCount(2, 'holdings')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonStructure([
                'holdings' => [
                    [
                        'id',
                        'symbol',
                        'name',
                        'isin',
                        'wkn',
                        'exchange',
                        'mic_code',
                        'instrument_type',
                        'country',
                        'currency',
                        'latest_price',
                        'start_price',
                        'end_price',
                        'end_price_24',
                        'end_price_48',
                        'start_price_date',
                        'end_price_date',
                        'end_price_24_date',
                        'end_price_48_date',
                        'historical_prices_fetching',
                        'position_pieces',
                        'latest_price_trend',
                        'latest_price_change_pct',
                        'latest_price_tick_trend',
                        'latest_price_status',
                        'price_status',
                        'latest_price_fetched_at',
                        'latest_price_source',
                        'latest_price_source_url',
                        'latest_price_as_of',
                        'trading_times',
                        'venue',
                        'price_type',
                        'price_spread_pct',
                        'recent_prices',
                        'intraday_prices',
                        'intraday_candles',
                        'daily_prices',
                        'validation_errors',
                        'created_at',
                    ],
                ],
            ]);
    }

    public function test_admin_can_list_all_watchlist_holdings_without_pagination(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->count(12)->create();

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?all=1')
            ->assertOk()
            ->assertJsonCount(12, 'holdings')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 12);
    }

    public function test_admin_listing_omits_chart_history_by_default(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-04',
            'close' => '123.45000000',
            'currency' => 'USD',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-04',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-04 09:00:00', 'UTC'),
            'close' => '124.56000000',
            'currency' => 'USD',
            'source_key' => 'eodhd_intraday',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonCount(0, 'holdings.0.daily_prices')
            ->assertJsonCount(0, 'holdings.0.intraday_prices')
            ->assertJsonCount(0, 'holdings.0.intraday_candles');
    }

    public function test_admin_listing_includes_chart_history_only_for_selected_chart_stock(): void
    {
        $admin = $this->adminUser();
        $selectedHolding = StockHolding::factory()->create([
            'name' => 'Alpha Selected',
        ]);
        $otherHolding = StockHolding::factory()->create([
            'name' => 'Beta Other',
        ]);

        foreach ([$selectedHolding, $otherHolding] as $holding) {
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-04',
                'close' => '123.45000000',
                'currency' => 'USD',
            ]);
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-04',
                'interval' => '5m',
                'as_of' => Carbon::parse('2026-06-04 09:00:00', 'UTC'),
                'close' => '124.56000000',
                'currency' => 'USD',
                'source_key' => 'eodhd_intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings?include_charts=1&chart_stock_id={$selectedHolding->id}")
            ->assertOk()
            ->assertJsonPath('holdings.0.id', $selectedHolding->id)
            ->assertJsonCount(1, 'holdings.0.daily_prices')
            ->assertJsonCount(1, 'holdings.0.intraday_candles')
            ->assertJsonPath('holdings.1.id', $otherHolding->id)
            ->assertJsonCount(0, 'holdings.1.daily_prices')
            ->assertJsonCount(0, 'holdings.1.intraday_candles');
    }

    public function test_admin_listing_can_include_chart_history_for_all_holdings_without_pagination(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');
        $admin = $this->adminUser();

        $firstHolding = StockHolding::factory()->create([
            'name' => 'Alpha ETF',
        ]);
        StockHolding::factory()->count(10)->create();
        $lastHolding = StockHolding::factory()->create([
            'name' => 'Zulu ETF',
        ]);

        foreach ([$firstHolding, $lastHolding] as $holding) {
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-01',
                'close' => '123.45000000',
                'currency' => 'EUR',
            ]);
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-01',
                'interval' => '5m',
                'as_of' => Carbon::parse('2026-06-01 09:00:00', 'UTC'),
                'close' => '124.56000000',
                'currency' => 'EUR',
                'source_key' => 'eodhd_intraday',
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1&all_chart_holdings=1&chart_range=1y')
            ->assertOk()
            ->assertJsonCount(12, 'holdings')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('holdings.0.id', $firstHolding->id)
            ->assertJsonCount(0, 'holdings.0.intraday_candles')
            ->assertJsonPath('holdings.11.id', $lastHolding->id)
            ->assertJsonCount(0, 'holdings.11.intraday_candles');

        $this->assertGreaterThanOrEqual(1, count($response->json('holdings.0.daily_prices')));
        $this->assertGreaterThanOrEqual(1, count($response->json('holdings.11.daily_prices')));
    }

    public function test_admin_listing_derives_period_chart_history_from_intraday_candles_when_daily_prices_are_missing(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'name' => 'Argentina ETF',
        ]);

        foreach ([
            ['trading_date' => '2026-06-03', 'as_of' => '2026-06-03 15:30:00', 'close' => '98.16000000', 'volume' => 1200],
            ['trading_date' => '2026-06-03', 'as_of' => '2026-06-03 20:00:00', 'close' => '97.92000000', 'volume' => 1400],
            ['trading_date' => '2026-06-04', 'as_of' => '2026-06-04 20:00:00', 'close' => '99.12000000', 'volume' => 1500],
        ] as $candle) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => $candle['trading_date'],
                'interval' => '5m',
                'as_of' => Carbon::parse($candle['as_of'], 'UTC'),
                'close' => $candle['close'],
                'volume' => $candle['volume'],
                'currency' => 'USD',
                'source_key' => 'eodhd_intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings?include_charts=1&chart_stock_id={$holding->id}&chart_range=3m")
            ->assertOk()
            ->assertJsonCount(2, 'holdings.0.daily_prices')
            ->assertJsonPath('holdings.0.daily_prices.0.trading_date', '2026-06-03')
            ->assertJsonPath('holdings.0.daily_prices.0.price', '97.92000000')
            ->assertJsonPath('holdings.0.daily_prices.0.volume', 2600)
            ->assertJsonPath('holdings.0.daily_prices.1.trading_date', '2026-06-04')
            ->assertJsonPath('holdings.0.daily_prices.1.price', '99.12000000')
            ->assertJsonPath('holdings.0.daily_prices.1.volume', 1500)
            ->assertJsonCount(0, 'holdings.0.intraday_prices')
            ->assertJsonCount(0, 'holdings.0.intraday_candles');
    }

    public function test_admin_listing_appends_newer_intraday_daily_closes_to_loaded_daily_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'name' => 'Argentina ETF',
            ]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-11',
                'close' => '469.70000000',
                'adjusted_close' => '469.70000000',
                'volume' => 123456,
                'currency' => 'USD',
            ]);

            foreach ([
                ['trading_date' => '2026-06-11', 'as_of' => '2026-06-11 20:00:00', 'close' => '999.00000000', 'volume' => 9000],
                ['trading_date' => '2026-06-12', 'as_of' => '2026-06-12 07:00:00', 'close' => '480.00000000', 'volume' => 1000],
                ['trading_date' => '2026-06-12', 'as_of' => '2026-06-12 15:30:00', 'close' => '481.50000000', 'volume' => 2000],
            ] as $candle) {
                StockHoldingIntradayCandle::query()->create([
                    'stock_holding_id' => $holding->id,
                    'trading_date' => $candle['trading_date'],
                    'interval' => '5m',
                    'as_of' => Carbon::parse($candle['as_of'], 'UTC'),
                    'close' => $candle['close'],
                    'volume' => $candle['volume'],
                    'currency' => 'USD',
                    'source_key' => 'eodhd_intraday',
                ]);
            }

            $this->actingAs($admin)
                ->getJson("/admin/watchlist/holdings?include_charts=1&chart_stock_id={$holding->id}&chart_range=3m")
                ->assertOk()
                ->assertJsonCount(2, 'holdings.0.daily_prices')
                ->assertJsonPath('holdings.0.daily_prices.0.trading_date', '2026-06-11')
                ->assertJsonPath('holdings.0.daily_prices.0.price', '469.70000000')
                ->assertJsonPath('holdings.0.daily_prices.0.volume', 123456)
                ->assertJsonPath('holdings.0.daily_prices.1.trading_date', '2026-06-12')
                ->assertJsonPath('holdings.0.daily_prices.1.price', '481.50000000')
                ->assertJsonPath('holdings.0.daily_prices.1.volume', 3000)
                ->assertJsonCount(0, 'holdings.0.intraday_candles');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_listing_appends_newer_realtime_daily_close_to_loaded_daily_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'name' => 'Realtime ETF',
                'currency' => 'EUR',
            ]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-12',
                'close' => '101.25000000',
                'adjusted_close' => '101.25000000',
                'volume' => 1000,
                'currency' => 'EUR',
            ]);

            $this->createRealtimeQuote($holding, '2026-06-12 15:30:00', '102.00000000');
            $this->createRealtimeQuote($holding, '2026-06-15 09:00:00', '103.00000000');
            $latestRealtimePrice = $this->createRealtimeQuote($holding, '2026-06-15 12:00:00', '104.50000000');
            $holding->update(['latest_realtime_price_id' => $latestRealtimePrice->id]);

            $this->actingAs($admin)
                ->getJson("/admin/watchlist/holdings?include_charts=1&chart_stock_id={$holding->id}&chart_range=3m")
                ->assertOk()
                ->assertJsonCount(2, 'holdings.0.daily_prices')
                ->assertJsonPath('holdings.0.daily_prices.0.trading_date', '2026-06-12')
                ->assertJsonPath('holdings.0.daily_prices.0.price', '101.25000000')
                ->assertJsonPath('holdings.0.daily_prices.0.volume', 1000)
                ->assertJsonPath('holdings.0.daily_prices.1.trading_date', '2026-06-15')
                ->assertJsonPath('holdings.0.daily_prices.1.price', '104.50000000')
                ->assertJsonPath('holdings.0.daily_prices.1.volume', null)
                ->assertJsonPath('holdings.0.daily_prices.1.currency', 'EUR');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_listing_includes_one_year_daily_stock_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'currency' => 'USD',
            ]);
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-04',
                'close' => '123.45000000',
                'adjusted_close' => '124.56000000',
                'volume' => 456789,
                'currency' => 'USD',
            ]);
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2025-05-30',
                'close' => '100.00000000',
                'adjusted_close' => '100.00000000',
                'currency' => 'USD',
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/watchlist/holdings?include_charts=1')
                ->assertOk()
                ->assertJsonCount(1, 'holdings.0.daily_prices')
                ->assertJsonPath('holdings.0.daily_prices.0.trading_date', '2026-06-04')
                ->assertJsonPath('holdings.0.daily_prices.0.price', '124.56000000')
                ->assertJsonPath('holdings.0.daily_prices.0.volume', 456789)
                ->assertJsonPath('holdings.0.daily_prices.0.currency', 'USD');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_listing_includes_recent_stored_intraday_candles_for_analyze_charts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $holding = StockHolding::factory()->create([
                'symbol' => 'LEER',
                'name' => 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
                'currency' => 'EUR',
            ]);

            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-12',
                'interval' => '5m',
                'as_of' => Carbon::parse('2026-06-12 09:00:00', 'UTC'),
                'close' => '42.60000000',
                'currency' => 'EUR',
            ]);
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-12',
                'interval' => '1h',
                'as_of' => Carbon::parse('2026-06-12 10:00:00', 'UTC'),
                'close' => '99.00000000',
                'currency' => 'EUR',
            ]);
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-05-01',
                'interval' => '5m',
                'as_of' => Carbon::parse('2026-05-01 09:00:00', 'UTC'),
                'close' => '41.00000000',
                'currency' => 'EUR',
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/watchlist/holdings?include_charts=1')
                ->assertOk()
                ->assertJsonCount(2, 'holdings.0.intraday_candles')
                ->assertJsonPath('holdings.0.intraday_candles.0.trading_date', '2026-05-01')
                ->assertJsonPath('holdings.0.intraday_candles.0.price', '41.00000000')
                ->assertJsonPath('holdings.0.intraday_candles.1.trading_date', '2026-06-12')
                ->assertJsonPath('holdings.0.intraday_candles.1.price', '42.60000000')
                ->assertJsonPath('holdings.0.intraday_candles.1.as_of', '2026-06-12T09:00:00+00:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_exchange_trading_times_omit_exchanges_without_stored_details(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::preventStrayRequests();

        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/exchange-trading-times')
            ->assertOk()
            ->assertJsonCount(0, 'exchange_trading_times')
            ->assertJsonPath('eodhd_api_usage.hour.used', 0)
            ->assertJsonPath('eodhd_api_usage.day.used', 0);

        Http::assertSentCount(0);
    }

    public function test_admin_can_list_exchange_trading_times_from_stored_eodhd_exchange_details(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::preventStrayRequests();

        EodhdExchange::query()->create([
            'code' => 'XETRA',
            'detail_code' => 'XETRA',
            'name' => 'XETRA Stock Exchange',
            'country' => 'Germany',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'operating_mic' => 'XETR',
            'trading_hours' => [
                'Open' => '09:00:00',
                'Close' => '17:30:00',
                'OpenUTC' => '07:00:00',
                'CloseUTC' => '15:30:00',
                'WorkingDays' => 'Mon,Tue,Wed,Thu,Fri',
            ],
            'holidays' => [],
        ]);

        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/exchange-trading-times')
            ->assertOk()
            ->assertJsonCount(1, 'exchange_trading_times')
            ->assertJsonPath('exchange_trading_times.0.code', 'XETRA')
            ->assertJsonPath('exchange_trading_times.0.name', 'XETRA Stock Exchange')
            ->assertJsonPath('exchange_trading_times.0.operating_mic', 'XETR')
            ->assertJsonPath('exchange_trading_times.0.timezone', 'Europe/Berlin')
            ->assertJsonPath('exchange_trading_times.0.open', '09:00:00')
            ->assertJsonPath('exchange_trading_times.0.close', '17:30:00')
            ->assertJsonPath('exchange_trading_times.0.open_utc', '07:00:00')
            ->assertJsonPath('exchange_trading_times.0.close_utc', '15:30:00')
            ->assertJsonPath('exchange_trading_times.0.working_days', 'Mon,Tue,Wed,Thu,Fri')
            ->assertJsonPath('exchange_trading_times.0.error', null)
            ->assertJsonPath('eodhd_api_usage.hour.used', 0)
            ->assertJsonPath('eodhd_api_usage.day.used', 0);

        Http::assertSentCount(0);
    }

    public function test_admin_exchange_trading_times_include_generic_index_exchange_for_loaded_index_data(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::preventStrayRequests();

        $admin = $this->adminUser();
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'name' => 'Austrian Traded Index in EUR',
            'isin' => 'AT0000999982',
            'exchange' => 'INDX',
            'instrument_type' => 'INDEX',
            'country' => 'Austria',
            'currency' => 'EUR',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'BBVAI',
            'name' => 'Accion IBEX 35 Cotizado Armonizado FI',
            'exchange' => 'MC',
            'mic_code' => 'XMAD',
            'instrument_type' => 'INDEX',
            'country' => 'Spain',
            'currency' => 'EUR',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/exchange-trading-times')
            ->assertOk()
            ->assertJsonCount(1, 'exchange_trading_times')
            ->assertJsonPath('exchange_trading_times.0.code', 'INDX')
            ->assertJsonPath('exchange_trading_times.0.name', 'Index Data')
            ->assertJsonPath('exchange_trading_times.0.operating_mic', null)
            ->assertJsonPath('exchange_trading_times.0.timezone', 'UTC')
            ->assertJsonPath('exchange_trading_times.0.open', '00:00:00')
            ->assertJsonPath('exchange_trading_times.0.close', '23:59:00')
            ->assertJsonPath('exchange_trading_times.0.open_utc', '00:00:00')
            ->assertJsonPath('exchange_trading_times.0.close_utc', '23:59:00')
            ->assertJsonPath('exchange_trading_times.0.working_days', 'Mon,Tue,Wed,Thu,Fri')
            ->assertJsonPath('eodhd_api_usage.hour.used', 0);

        Http::assertSentCount(0);
    }

    public function test_admin_exchange_trading_times_include_lunch_break_sessions_from_stored_exchange_details(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::preventStrayRequests();

        EodhdExchange::query()->create([
            'code' => 'SHG',
            'detail_code' => 'XSHG',
            'name' => 'Shanghai Stock Exchange',
            'country' => 'China',
            'currency' => 'CNY',
            'timezone' => 'Asia/Shanghai',
            'operating_mic' => 'XSHG',
            'trading_hours' => [
                'Open' => '09:30:00',
                'Close' => '15:00:00',
                'LunchBegin' => '11:30:00',
                'LunchEnd' => '13:00:00',
                'OpenUTC' => '01:30:00',
                'CloseUTC' => '07:00:00',
                'WorkingDays' => 'Mon,Tue,Wed,Thu,Fri',
            ],
            'holidays' => [
                [
                    'Holiday' => 'Dragon Boat Festival',
                    'Date' => '2026-06-19',
                    'Type' => 'official',
                ],
            ],
        ]);

        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => '000001',
            'exchange' => 'Shanghai Stock Exchange',
            'mic_code' => 'XSHG',
            'country' => 'China',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/exchange-trading-times')
            ->assertOk()
            ->assertJsonCount(1, 'exchange_trading_times')
            ->assertJsonPath('exchange_trading_times.0.code', 'SHG')
            ->assertJsonPath('exchange_trading_times.0.name', 'Shanghai Stock Exchange')
            ->assertJsonPath('exchange_trading_times.0.operating_mic', 'XSHG')
            ->assertJsonPath('exchange_trading_times.0.timezone', 'Asia/Shanghai')
            ->assertJsonPath('exchange_trading_times.0.open', '09:30:00')
            ->assertJsonPath('exchange_trading_times.0.close', '15:00:00')
            ->assertJsonPath('exchange_trading_times.0.lunch_begin', '11:30:00')
            ->assertJsonPath('exchange_trading_times.0.lunch_end', '13:00:00')
            ->assertJsonPath('exchange_trading_times.0.sessions.0.open', '09:30:00')
            ->assertJsonPath('exchange_trading_times.0.sessions.0.close', '11:30:00')
            ->assertJsonPath('exchange_trading_times.0.sessions.1.open', '13:00:00')
            ->assertJsonPath('exchange_trading_times.0.sessions.1.close', '15:00:00')
            ->assertJsonPath('exchange_trading_times.0.holidays.0', '2026-06-19');

        Http::assertSentCount(0);
    }

    public function test_admin_listing_hides_stale_holding_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 08:30:00'));
        StockHolding::factory()->create([
            'symbol' => 'LEER',
            'latest_price' => '43.370000',
            'latest_price_as_of' => '2026-06-01 11:10:33',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Rome',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LEER')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null)
            ->assertJsonPath('holdings.0.venue', null)
            ->assertJsonPath('holdings.0.price_type', null);
    }

    public function test_admin_listing_hides_date_only_stale_holding_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 12:00:00'));
        StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'latest_price' => '26.990000',
            'latest_price_as_of' => '03.06.2026',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'EXXX')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'stale')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null)
            ->assertJsonPath('holdings.0.venue', null)
            ->assertJsonPath('holdings.0.price_type', null);
    }

    public function test_admin_listing_uses_selected_stock_price_status_before_stale_holding_status(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'France',
            'currency' => 'EUR',
            'latest_price' => '469.200000',
            'latest_price_as_of' => '2026-06-03 17:24:53',
            'price_status' => 'stale',
        ]);
        $stockPrice = StockPrice::factory()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://eodhd.com/api/real-time/AMES.XETRA?fmt=json',
            'source_quality' => 'market_data_vendor',
            'venue' => 'Xetra',
            'mic' => 'XETR',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'symbol' => 'AMES',
            'currency' => 'EUR',
            'price' => '469.20000000',
            'price_type' => 'last',
            'as_of' => '2026-06-04 15:35:00',
            'fetched_at' => '2026-06-04 20:47:05',
            'freshness_status' => 'closed_market',
            'validation_status' => 'valid',
        ]);
        $holding->update([
            'latest_stock_price_id' => $stockPrice->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'AMES')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'closed_market')
            ->assertJsonPath('holdings.0.price_status', 'closed_market')
            ->assertJsonPath('holdings.0.latest_price_as_of', null)
            ->assertJsonPath('holdings.0.latest_price_source', null)
            ->assertJsonPath('holdings.0.venue', null)
            ->assertJsonPath('holdings.0.price_type', null);
    }

    public function test_admin_listing_hides_source_time_for_unavailable_holding_prices(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'LYMH',
            'latest_price' => null,
            'latest_price_fetched_at' => '2026-06-03 05:40:55',
            'latest_price_as_of' => '03.06.2026 05:32:08 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYMH')
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.latest_price_status', 'unavailable')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null)
            ->assertJsonPath('holdings.0.latest_price_tick_trend', null)
            ->assertJsonPath('holdings.0.latest_price_as_of', null);
    }

    public function test_admin_listing_includes_trading_session_start_and_end_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '191.500000',
            'latest_price_fetched_at' => '2026-06-04 08:20:00',
            'latest_price_as_of' => '2026-06-04 08:15:00',
            'start_price' => '191.00000000',
            'end_price' => '193.00000000',
            'end_price_24' => '194.00000000',
            'end_price_48' => '189.50000000',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);
        // Berlin (CEST, +02:00): dashboard prices come from realtime rows for the relevant trading day.
        $this->createMedianQuote($holding, '2026-06-02 07:08:00', '189.00', 'historical_session_start');
        $this->createMedianQuote($holding, '2026-06-03 07:05:00', '192.00', 'historical_session_start');
        $this->createMedianQuote($holding, '2026-06-03 15:45:00', '193.00', 'historical_session_end');
        $this->createRealtimeQuote($holding, '2026-06-04 06:30:00', '190.00');
        $this->createRealtimeQuote($holding, '2026-06-04 07:10:00', '191.00', 'historical_session_start');
        $this->createRealtimeQuote($holding, '2026-06-04 08:15:00', '191.50');
        $this->createEndOfDayPrice($holding, '2026-06-04', '999.00');
        $this->createEndOfDayPrice($holding, '2026-06-03', '194.25');
        $this->createEndOfDayPrice($holding, '2026-06-03', '194.50');
        $this->createEndOfDayPrice($holding, '2026-06-02', '189.75');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYXIB')
            ->assertJsonPath('holdings.0.latest_price', '191.500000')
            ->assertJsonPath('holdings.0.start_price', '190.000000')
            ->assertJsonPath('holdings.0.end_price', '191.500000')
            ->assertJsonPath('holdings.0.end_price_24', '194.500000')
            ->assertJsonPath('holdings.0.end_price_48', '189.750000')
            ->assertJsonPath('holdings.0.start_price_date', '2026-06-04')
            ->assertJsonPath('holdings.0.end_price_date', '2026-06-04')
            ->assertJsonPath('holdings.0.end_price_24_date', '2026-06-03')
            ->assertJsonPath('holdings.0.end_price_48_date', '2026-06-02')
            ->assertJsonPath('holdings.0.historical_prices_fetching', false)
            ->assertJsonPath('holdings.0.latest_price_trend', 'down')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '-1.54');
    }

    public function test_admin_listing_uses_realtime_price_for_end_price_when_it_is_newer_than_intraday_candle(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'end_price' => '90.00000000',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createIntradayCandle($holding, '2026-06-04', '2026-06-04 08:00:00', '101.00');
        $this->createRealtimeQuote($holding, '2026-06-04 08:05:00', '102.25');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.end_price', '102.250000')
            ->assertJsonPath('holdings.0.end_price_date', '2026-06-04');
    }

    public function test_admin_listing_uses_intraday_candle_close_for_end_price_when_it_is_newer_than_realtime_price(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'end_price' => '90.00000000',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createRealtimeQuote($holding, '2026-06-04 08:00:00', '102.25');
        $this->createIntradayCandle($holding, '2026-06-04', '2026-06-04 08:05:00', '103.75');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.end_price', '103.750000')
            ->assertJsonPath('holdings.0.end_price_date', '2026-06-04');
    }

    public function test_admin_listing_uses_intraday_candles_for_dashboard_prices_when_realtime_is_on_same_date(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createRealtimeQuote($holding, '2026-06-04 07:00:00', '100.00');
        $this->createRealtimeQuote($holding, '2026-06-04 08:00:00', '101.00');
        $this->createIntradayCandle($holding, '2026-06-04', '2026-06-04 07:05:00', '200.00');
        $this->createIntradayCandle($holding, '2026-06-04', '2026-06-04 08:10:00', '201.25');
        $this->createEndOfDayPrice($holding, '2026-06-03', '90.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', '201.250000')
            ->assertJsonPath('holdings.0.start_price', '200.000000')
            ->assertJsonPath('holdings.0.end_price_24', '90.000000')
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-04T08:10:00+02:00')
            ->assertJsonPath('holdings.0.latest_price_source', 'EODHD intraday')
            ->assertJsonPath('holdings.0.price_type', 'intraday');
    }

    public function test_admin_listing_uses_realtime_prices_for_dashboard_prices_when_realtime_date_is_newer_than_intraday(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createIntradayCandle($holding, '2026-06-04', '2026-06-04 08:10:00', '201.25');
        $this->createRealtimeQuote($holding, '2026-06-05 07:00:00', '102.50');
        $this->createRealtimeQuote($holding, '2026-06-05 08:15:00', '103.75');
        $this->createEndOfDayPrice($holding, '2026-06-04', '95.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', '103.750000')
            ->assertJsonPath('holdings.0.start_price', '102.500000')
            ->assertJsonPath('holdings.0.end_price_24', '95.000000')
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-05T08:15:00+00:00')
            ->assertJsonPath('holdings.0.latest_price_source', 'EODHD real-time')
            ->assertJsonPath('holdings.0.price_type', 'last');
    }

    public function test_admin_listing_does_not_queue_missing_historical_session_prices(): void
    {
        Queue::fake();
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 10:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'latest_price' => '191.500000',
            'latest_price_fetched_at' => '2026-06-04 08:20:00',
            'latest_price_as_of' => '2026-06-04 08:15:00',
            'start_price' => '191.00000000',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);
        $this->createMedianQuote($holding, '2026-06-04 07:10:00', '191.00', 'historical_session_start');
        $this->createRealtimeQuote($holding, '2026-06-04 07:10:00', '191.00', 'historical_session_start');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.symbol', 'LYXIB')
            ->assertJsonPath('holdings.0.start_price', '191.000000')
            ->assertJsonPath('holdings.0.end_price_24', null)
            ->assertJsonPath('holdings.0.end_price_48', null)
            ->assertJsonPath('holdings.0.historical_prices_fetching', false);

        Queue::assertNothingPushed();
    }

    public function test_admin_listing_serializes_stored_stock_price_source_time_as_utc_instant(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'Europe/Madrid'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'isin' => 'FR0010251744',
            'wkn' => 'LYX0A6',
            'exchange' => 'Madrid',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
        ]);
        $this->createRealtimeQuote($holding, '2026-06-03 15:35:00', '191.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-03T15:35:00+00:00');
    }

    public function test_admin_listing_compares_latest_price_to_previous_stored_price(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 13:00:00', 'Europe/Berlin'));

        $upHolding = StockHolding::factory()->create([
            'name' => 'A Up',
            'symbol' => 'UP',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createRealtimeQuote($upHolding, '2026-06-03 10:00:00', '100.00');
        $upLatestPrice = $this->createRealtimeQuote($upHolding, '2026-06-03 10:20:00', '101.00');
        $upHolding->update([
            'latest_price' => '101.000000',
            'latest_realtime_price_id' => $upLatestPrice->id,
        ]);

        $downHolding = StockHolding::factory()->create([
            'name' => 'B Down',
            'symbol' => 'DOWN',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createRealtimeQuote($downHolding, '2026-06-03 10:00:00', '100.00');
        $downLatestPrice = $this->createRealtimeQuote($downHolding, '2026-06-03 10:20:00', '99.00');
        $downHolding->update([
            'latest_price' => '99.000000',
            'latest_realtime_price_id' => $downLatestPrice->id,
        ]);

        $flatHolding = StockHolding::factory()->create([
            'name' => 'C Flat',
            'symbol' => 'FLAT',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $this->createRealtimeQuote($flatHolding, '2026-06-03 10:00:00', '100.00');
        $flatLatestPrice = $this->createRealtimeQuote($flatHolding, '2026-06-03 10:20:00', '100.00');
        $flatHolding->update([
            'latest_price' => '100.000000',
            'latest_realtime_price_id' => $flatLatestPrice->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price_tick_trend', 'up')
            ->assertJsonPath('holdings.1.latest_price_tick_trend', 'down')
            ->assertJsonPath('holdings.2.latest_price_tick_trend', 'flat');
    }

    public function test_admin_listing_compares_latest_price_to_previous_stored_price_with_same_source_time(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'Europe/Madrid'));
        $holding = StockHolding::factory()->create([
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'symbol' => 'LYXIB',
            'isin' => 'FR0010251744',
            'currency' => 'EUR',
            'price_status' => 'closed_market',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
        ]);

        $this->createRealtimeQuote($holding, '2026-06-03 15:35:00', '191.00');
        $latestPrice = $this->createRealtimeQuote($holding, '2026-06-03 15:35:00', '191.04');
        $holding->update([
            'latest_price' => '191.040000',
            'latest_realtime_price_id' => $latestPrice->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', '191.040000')
            ->assertJsonPath('holdings.0.latest_price_tick_trend', 'up');
    }

    public function test_admin_listing_includes_previous_trading_day_last_realtime_price_with_latest_trading_day_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 15:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'RECENT',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->createRealtimeQuote($holding, '2026-06-02 17:50:00', '98.00');
        $this->createRealtimeQuote($holding, '2026-06-03 09:00:00', '99.00');
        $this->createRealtimeQuote($holding, '2026-06-03 12:00:00', '100.00');
        $this->createRealtimeQuote($holding, '2026-06-03 12:00:00', '100.25');
        StockPrice::query()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|raw-source|2026-06-03 13:00:00"),
            'source_key' => 'tradegate',
            'source_name' => 'Tradegate',
            'source_url' => 'https://example.com/raw',
            'source_quality' => 'official_venue',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => '100.50000000',
            'price_type' => 'indicative_mid',
            'as_of' => Carbon::parse('2026-06-03 13:00:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-03 13:00:00', 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
        ]);
        $latestPrice = $this->createRealtimeQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_realtime_price_id' => $latestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.recent_prices.0.price', '98.00000000')
            ->assertJsonPath('holdings.0.recent_prices.1.price', '99.00000000')
            ->assertJsonPath('holdings.0.recent_prices.2.price', '100.00000000')
            ->assertJsonPath('holdings.0.recent_prices.3.price', '100.25000000')
            ->assertJsonPath('holdings.0.recent_prices.4.price', '101.00000000')
            ->assertJsonPath('holdings.0.recent_prices.1.as_of', '2026-06-03T09:00:00+00:00')
            ->assertJsonMissingPath('holdings.0.recent_prices.5');
    }

    public function test_admin_can_list_latest_realtime_prices_for_the_same_vienna_date(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'LIVE',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);

        $this->createRealtimeQuote($holding, '2026-06-04 20:00:00', '98.00');
        $firstLatestDayPrice = $this->createRealtimeQuote($holding, '2026-06-04 22:30:00', '99.00');
        $lastLatestDayPrice = $this->createRealtimeQuote($holding, '2026-06-05 15:30:00', '101.00');
        $firstLatestDayPrice->update(['venue' => 'Tradegate']);
        $lastLatestDayPrice->update(['venue' => 'XETRA']);

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/realtime-prices/latest")
            ->assertOk()
            ->assertJsonPath('holding.id', $holding->id)
            ->assertJsonPath('date', '2026-06-05')
            ->assertJsonPath('entries.0.price', '99.00000000')
            ->assertJsonPath('entries.0.as_of', '2026-06-04T22:30:00+00:00')
            ->assertJsonPath('entries.0.venue', 'Tradegate')
            ->assertJsonPath('entries.1.price', '101.00000000')
            ->assertJsonPath('entries.1.venue', 'XETRA')
            ->assertJsonMissingPath('entries.2');
    }

    public function test_admin_can_list_intraday_candles_for_the_latest_seven_trading_dates(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'HIST',
            'currency' => 'EUR',
        ]);

        foreach (range(1, 8) as $day) {
            $tradingDate = sprintf('2026-06-%02d', $day);
            $this->createIntradayCandle($holding, $tradingDate, "{$tradingDate} 09:00:00", (string) (100 + $day));
        }

        $this->createIntradayCandle($holding, '2026-06-08', '2026-06-08 09:05:00', '109.50');

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles/latest-days")
            ->assertOk()
            ->assertJsonPath('holding.id', $holding->id)
            ->assertJsonPath('dates.0', '2026-06-02')
            ->assertJsonPath('dates.6', '2026-06-08')
            ->assertJsonPath('entries.0.trading_date', '2026-06-08')
            ->assertJsonPath('entries.0.price', '109.50000000')
            ->assertJsonPath('entries.1.trading_date', '2026-06-08')
            ->assertJsonPath('entries.1.price', '108.00000000')
            ->assertJsonPath('entries.7.trading_date', '2026-06-02')
            ->assertJsonPath('entries.7.price', '102.00000000')
            ->assertJsonMissingPath('entries.8');
    }

    public function test_admin_can_list_latest_thirty_end_of_day_prices(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'EOD',
            'currency' => 'EUR',
        ]);

        foreach (range(1, 31) as $day) {
            $tradingDate = sprintf('2026-05-%02d', $day);
            $this->createEndOfDayPrice($holding, $tradingDate, (string) (100 + $day));
        }

        $this->createEndOfDayPrice($holding, '2026-05-31', '132.50');
        $this->createMedianQuote($holding, '2026-06-01 12:00:00', '999.00');

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/end-of-day-prices/latest-days")
            ->assertOk()
            ->assertJsonPath('holding.id', $holding->id)
            ->assertJsonPath('entries.0.price', '132.50000000')
            ->assertJsonPath('entries.0.as_of', '2026-05-31T21:59:59+00:00')
            ->assertJsonPath('entries.1.price', '130.00000000')
            ->assertJsonPath('entries.29.price', '102.00000000')
            ->assertJsonMissingPath('entries.30');
    }

    public function test_admin_listing_falls_back_to_latest_available_trading_day_prices(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-06 12:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'FALLBACK',
            'currency' => 'EUR',
            'price_status' => 'closed_market',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->createRealtimeQuote($holding, '2026-06-02 17:00:00', '98.00');
        $this->createRealtimeQuote($holding, '2026-06-03 12:00:00', '100.00');
        $latestPrice = $this->createRealtimeQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_realtime_price_id' => $latestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.recent_prices_are_fallback', true)
            ->assertJsonPath('holdings.0.recent_prices.0.price', '98.00000000')
            ->assertJsonPath('holdings.0.recent_prices.1.price', '100.00000000')
            ->assertJsonPath('holdings.0.recent_prices.2.price', '101.00000000')
            ->assertJsonMissingPath('holdings.0.recent_prices.3');
    }

    public function test_admin_listing_does_not_use_legacy_stock_prices_for_realtime_price_rows(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 15:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'LEGACY',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $latestPrice = $this->createMedianQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_stock_price_id' => $latestPrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonCount(0, 'holdings.0.recent_prices')
            ->assertJsonPath('holdings.0.recent_prices_are_fallback', false);
    }

    public function test_admin_listing_uses_latest_realtime_price_in_price_list(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'LIVE',
            'currency' => 'EUR',
            'latest_price' => '99.000000',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $latestRealtimePrice = $this->createRealtimeQuote($holding, '2026-06-15 12:00:00', '104.50');
        $holding->update(['latest_realtime_price_id' => $latestRealtimePrice->id]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', '104.500000')
            ->assertJsonPath('holdings.0.latest_price_as_of', '2026-06-15T12:00:00+00:00')
            ->assertJsonPath('holdings.0.latest_price_source', 'EODHD real-time')
            ->assertJsonPath('holdings.0.recent_prices.0.price', '104.50000000');
    }

    public function test_admin_listing_includes_stored_eodhd_intraday_prices_from_latest_available_day(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 18:10:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'INTRA',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (range(0, 4) as $index) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-03',
                'interval' => '5m',
                'close' => number_format(90 + $index, 8, '.', ''),
                'currency' => 'EUR',
                'as_of' => Carbon::parse('2026-06-03 09:00:00', 'UTC')->addMinutes($index * 20),
                'source_name' => 'EODHD intraday',
            ]);
        }

        foreach (range(0, 19) as $index) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-04',
                'interval' => '5m',
                'close' => number_format(100 + $index, 8, '.', ''),
                'currency' => 'EUR',
                'as_of' => Carbon::parse('2026-06-04 09:00:00', 'UTC')->addMinutes($index * 5),
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(20, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '100.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.0.as_of', '2026-06-04T09:00:00+02:00')
            ->assertJsonPath('holdings.0.intraday_prices.19.price', '119.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.19.as_of', '2026-06-04T10:35:00+02:00');

        $this->assertSame(
            20,
            StockHoldingIntradayCandle::query()
                ->where('stock_holding_id', $holding->id)
                ->whereDate('trading_date', '2026-06-04')
                ->count(),
        );
    }

    public function test_admin_listing_includes_compact_intraday_samples_for_one_week_chart(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-15 12:10:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'WEEK',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (['2026-06-07', '2026-06-08', '2026-06-09'] as $dayIndex => $tradingDate) {
            foreach (range(0, 9) as $index) {
                StockHoldingIntradayCandle::query()->create([
                    'stock_holding_id' => $holding->id,
                    'trading_date' => $tradingDate,
                    'interval' => '5m',
                    'close' => number_format(100 + ($dayIndex * 10) + $index, 8, '.', ''),
                    'currency' => 'EUR',
                    'as_of' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC')->addMinutes($index * 5),
                    'timestamp' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC')->addMinutes($index * 5)->timestamp,
                    'source_name' => 'EODHD intraday',
                ]);
            }
        }

        $this->createRealtimeQuote($holding, '2026-06-15 09:49:00', '130.00');

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings?include_charts=1&chart_stock_id={$holding->id}&chart_range=1w")
            ->assertOk()
            ->assertJsonCount(11, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '110.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.0.as_of', '2026-06-08T09:00:00+02:00')
            ->assertJsonPath('holdings.0.intraday_prices.4.price', '119.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.5.price', '120.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.9.price', '129.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.10.price', '130.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.10.as_of', '2026-06-15T09:49:00+00:00');
    }

    public function test_admin_listing_serializes_eodhd_intraday_candle_time_from_timestamp(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-12 12:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYMH',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-12',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-12 07:05:00', 'Europe/Vienna'),
            'timestamp' => 1781247900,
            'gmtoffset' => 0,
            'datetime' => '2026-06-12 07:05:00',
            'close' => '2.66300000',
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '2.66300000')
            ->assertJsonPath('holdings.0.intraday_prices.0.as_of', '2026-06-12T09:05:00+02:00');
    }

    public function test_admin_listing_uses_stored_intraday_price_rows_when_candles_are_sparse(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-12 18:10:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-12',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-12 07:10:00', 'UTC'),
            'close' => '477.75000000',
            'currency' => 'EUR',
            'source_name' => 'EODHD intraday',
        ]);

        foreach ([
            ['as_of' => '2026-06-12 07:05:00', 'price' => '477.75000000'],
            ['as_of' => '2026-06-12 07:10:00', 'price' => '481.50000000'],
        ] as $index => $priceRow) {
            StockHoldingIntradayPrice::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-12',
                'sample_index' => $index,
                'price' => $priceRow['price'],
                'currency' => 'EUR',
                'as_of' => Carbon::parse($priceRow['as_of'], 'UTC'),
                'source_name' => 'EODHD intraday',
                'price_type' => 'intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(2, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '477.75000000')
            ->assertJsonPath('holdings.0.intraday_prices.0.as_of', '2026-06-12T09:05:00+02:00')
            ->assertJsonPath('holdings.0.intraday_prices.1.price', '481.50000000')
            ->assertJsonPath('holdings.0.intraday_prices.1.as_of', '2026-06-12T09:10:00+02:00');
    }

    public function test_admin_can_fetch_and_store_last_trading_day_five_minute_intraday_candles(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-06 12:00:00', 'UTC'));
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([
                [
                    'timestamp' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => '2026-06-05 07:00:00',
                    'open' => 470.10,
                    'high' => 471.50,
                    'low' => 469.90,
                    'close' => 470.15,
                    'volume' => 12345,
                ],
                [
                    'timestamp' => Carbon::parse('2026-06-05 07:05:00', 'UTC')->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => '2026-06-05 07:05:00',
                    'open' => 470.15,
                    'high' => 472.00,
                    'low' => 470.00,
                    'close' => 471.75,
                    'volume' => 23456,
                ],
            ]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles")
            ->assertOk()
            ->assertJsonPath('holding.id', $holding->id)
            ->assertJsonPath('intraday.title', 'Intraday 05.06.2026')
            ->assertJsonPath('intraday.trading_date', '2026-06-05')
            ->assertJsonPath('intraday.interval', '5m')
            ->assertJsonCount(7, 'intraday_days')
            ->assertJsonPath('intraday_days.0.trading_date', '2026-06-05')
            ->assertJsonPath('intraday_days.1.trading_date', '2026-06-04')
            ->assertJsonPath('intraday_days.2.trading_date', '2026-06-03')
            ->assertJsonPath('intraday_days.3.trading_date', '2026-06-02')
            ->assertJsonPath('intraday_days.4.trading_date', '2026-06-01')
            ->assertJsonPath('intraday_days.5.trading_date', '2026-05-29')
            ->assertJsonPath('intraday_days.6.trading_date', '2026-05-28')
            ->assertJsonCount(2, 'intraday.rows')
            ->assertJsonPath('intraday.rows.0.timestamp', Carbon::parse('2026-06-05 07:00:00', 'UTC')->timestamp)
            ->assertJsonPath('intraday.rows.0.gmtoffset', 0)
            ->assertJsonPath('intraday.rows.0.datetime', '2026-06-05 07:00:00')
            ->assertJsonPath('intraday.rows.0.open', '470.10000000')
            ->assertJsonPath('intraday.rows.0.high', '471.50000000')
            ->assertJsonPath('intraday.rows.0.low', '469.90000000')
            ->assertJsonPath('intraday.rows.0.close', '470.15000000')
            ->assertJsonPath('intraday.rows.0.volume', 12345)
            ->assertJsonMissingPath('intraday.rows.0.currency');

        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'datetime' => '2026-06-05 07:05:00',
            'close' => '471.75000000',
            'volume' => 23456,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/AMES.XETRA')
            && str_contains($request->url(), 'interval=5m'));
        Http::assertSentCount(7);
    }

    public function test_admin_intraday_candle_detail_uses_stored_last_trading_day_rows(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-06 12:00:00', 'UTC'));
        Http::fake();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (['2026-06-05', '2026-06-04', '2026-06-03', '2026-06-02', '2026-06-01', '2026-05-29', '2026-05-28'] as $index => $tradingDate) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => $tradingDate,
                'interval' => '5m',
                'as_of' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC'),
                'timestamp' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC')->timestamp,
                'gmtoffset' => 0,
                'datetime' => "{$tradingDate} 07:00:00",
                'open' => (string) (470.1 - $index),
                'high' => (string) (471.5 - $index),
                'low' => (string) (469.9 - $index),
                'close' => (string) (470.15 - $index),
                'volume' => 12345 - $index,
                'currency' => 'EUR',
                'source_key' => 'eodhd_intraday',
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles")
            ->assertOk()
            ->assertJsonPath('intraday.title', 'Intraday 05.06.2026')
            ->assertJsonCount(7, 'intraday_days')
            ->assertJsonCount(1, 'intraday.rows')
            ->assertJsonPath('intraday.rows.0.close', '470.15000000');

        Http::assertNothingSent();
    }

    public function test_admin_intraday_candle_detail_returns_latest_seven_stored_days(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-06 12:00:00', 'UTC'));
        Http::fake();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (['2026-06-05', '2026-06-04', '2026-06-03', '2026-06-02', '2026-06-01', '2026-05-29', '2026-05-28', '2026-05-27'] as $index => $tradingDate) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => $tradingDate,
                'interval' => '5m',
                'as_of' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC'),
                'timestamp' => Carbon::parse("{$tradingDate} 07:00:00", 'UTC')->timestamp,
                'gmtoffset' => 0,
                'datetime' => "{$tradingDate} 07:00:00",
                'open' => (string) (470 + $index),
                'high' => (string) (471 + $index),
                'low' => (string) (469 + $index),
                'close' => (string) (470.15 + $index),
                'volume' => 12345 + $index,
                'currency' => 'EUR',
                'source_key' => 'eodhd_intraday',
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles")
            ->assertOk()
            ->assertJsonCount(7, 'intraday_days')
            ->assertJsonPath('intraday_days.0.trading_date', '2026-06-05')
            ->assertJsonPath('intraday_days.1.trading_date', '2026-06-04')
            ->assertJsonPath('intraday_days.2.trading_date', '2026-06-03')
            ->assertJsonPath('intraday_days.3.trading_date', '2026-06-02')
            ->assertJsonPath('intraday_days.4.trading_date', '2026-06-01')
            ->assertJsonPath('intraday_days.5.trading_date', '2026-05-29')
            ->assertJsonPath('intraday_days.6.trading_date', '2026-05-28')
            ->assertJsonMissingPath('intraday_days.7')
            ->assertJsonPath('intraday.trading_date', '2026-06-05');

        Http::assertNothingSent();
    }

    public function test_admin_intraday_candle_detail_returns_current_trading_day_first_when_rows_are_stored(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-08 16:40:00', 'Europe/Berlin'));
        Http::fake();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (['2026-06-08', '2026-06-05', '2026-06-04', '2026-06-03', '2026-06-02', '2026-06-01', '2026-05-29'] as $index => $tradingDate) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => $tradingDate,
                'interval' => '5m',
                'as_of' => Carbon::parse("{$tradingDate} 13:00:00", 'UTC'),
                'timestamp' => Carbon::parse("{$tradingDate} 13:00:00", 'UTC')->timestamp,
                'gmtoffset' => 0,
                'datetime' => "{$tradingDate} 13:00:00",
                'close' => (string) (470.20 + $index),
                'currency' => 'EUR',
                'source_key' => 'eodhd_intraday',
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles")
            ->assertOk()
            ->assertJsonPath('intraday.trading_date', '2026-06-08')
            ->assertJsonPath('intraday_days.0.trading_date', '2026-06-08')
            ->assertJsonPath('intraday_days.1.trading_date', '2026-06-05')
            ->assertJsonPath('intraday_days.2.trading_date', '2026-06-04')
            ->assertJsonPath('intraday_days.3.trading_date', '2026-06-03')
            ->assertJsonPath('intraday_days.4.trading_date', '2026-06-02')
            ->assertJsonPath('intraday_days.5.trading_date', '2026-06-01')
            ->assertJsonPath('intraday_days.6.trading_date', '2026-05-29')
            ->assertJsonMissingPath('intraday_days.7')
            ->assertJsonPath('intraday.rows.0.close', '470.20000000');

        Http::assertNothingSent();
    }

    public function test_admin_intraday_candle_detail_returns_newer_realtime_day_first(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-15 09:30:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-12',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-12 15:30:00', 'UTC'),
            'timestamp' => Carbon::parse('2026-06-12 15:30:00', 'UTC')->timestamp,
            'gmtoffset' => 0,
            'datetime' => '2026-06-12 15:30:00',
            'close' => '481.50000000',
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
        ]);
        $this->createRealtimeQuote($holding, '2026-06-15 07:05:00', '488.75');
        $this->createRealtimeQuote($holding, '2026-06-15 07:22:00', '490.80');

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/{$holding->id}/intraday-candles")
            ->assertOk()
            ->assertJsonPath('intraday.title', 'Intraday 15.06.2026')
            ->assertJsonPath('intraday.trading_date', '2026-06-15')
            ->assertJsonPath('intraday.interval', 'realtime')
            ->assertJsonCount(2, 'intraday.rows')
            ->assertJsonPath('intraday.rows.0.timestamp', Carbon::parse('2026-06-15 07:05:00', 'UTC')->timestamp)
            ->assertJsonPath('intraday.rows.0.close', '488.75000000')
            ->assertJsonPath('intraday.rows.1.timestamp', Carbon::parse('2026-06-15 07:22:00', 'UTC')->timestamp)
            ->assertJsonPath('intraday.rows.1.close', '490.80000000')
            ->assertJsonPath('intraday_days.0.trading_date', '2026-06-15')
            ->assertJsonPath('intraday_days.1.trading_date', '2026-06-12');
    }

    public function test_admin_listing_fetches_and_stores_eodhd_intraday_candles_when_session_has_no_stored_intraday_rows(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response(
                collect(range(0, 300))
                    ->map(fn (int $index): array => [
                        'timestamp' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->addMinutes($index)->timestamp,
                        'close' => 470.15 + $index,
                    ])
                    ->all(),
            ),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(301, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '470.15000000')
            ->assertJsonPath('holdings.0.intraday_prices.300.price', '770.15000000');

        $this->assertSame(301, StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->count());
        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'close' => '770.15000000',
            'source_name' => 'EODHD intraday',
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/AMES.XETRA'));
    }

    public function test_admin_listing_fetches_five_minute_eodhd_intraday_candles(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake(fn (Request $request) => str_contains($request->url(), 'interval=1m')
            ? Http::response([])
            : Http::response(
                collect(range(0, 102))
                    ->map(fn (int $index): array => [
                        'timestamp' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->addMinutes($index * 5)->timestamp,
                        'datetime' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->addMinutes($index * 5)->toDateTimeString(),
                        'close' => 42.60 + ($index / 100),
                    ])
                    ->all(),
            ));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LEER',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(103, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '42.60000000')
            ->assertJsonPath('holdings.0.intraday_prices.0.as_of', '2026-06-05T09:00:00+02:00')
            ->assertJsonPath('holdings.0.intraday_prices.102.price', '43.62000000');

        $this->assertSame(103, StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/LEER.XETRA')
            && str_contains($request->url(), 'interval=5m'));
    }

    public function test_admin_listing_does_not_fetch_eodhd_intraday_prices_when_session_already_has_stored_intraday_rows(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (range(0, 3) as $index) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-05',
                'interval' => '5m',
                'close' => number_format(460 + $index, 8, '.', ''),
                'currency' => 'EUR',
                'as_of' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->addMinutes($index * 10),
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(4, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '460.00000000')
            ->assertJsonPath('holdings.0.intraday_prices.3.price', '463.00000000');

        Http::assertNothingSent();
    }

    public function test_admin_listing_does_not_fabricate_intraday_samples_when_eodhd_intraday_is_sparse(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(0, 'holdings.0.intraday_prices');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(0, 'holdings.0.intraday_prices');

        $this->assertSame(0, StockHoldingIntradayCandle::query()->where('stock_holding_id', $holding->id)->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/AMES.XETRA'));
        Http::assertSentCount(1);
    }

    public function test_admin_listing_uses_realtime_prices_when_eodhd_intraday_has_no_valid_rows(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/intraday/SEC0.XETRA*' => Http::response([]),
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'SEC0',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $this->createRealtimeQuote($holding, '2026-06-04 15:30:00', '17.12');
        $this->createRealtimeQuote($holding, '2026-06-05 07:00:00', '17.50');
        $this->createRealtimeQuote($holding, '2026-06-05 12:20:00', '17.75');
        $this->createRealtimeQuote($holding, '2026-06-05 15:30:00', '17.87');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(4, 'holdings.0.intraday_prices')
            ->assertJsonPath('holdings.0.intraday_prices.0.price', '17.12000000')
            ->assertJsonPath('holdings.0.intraday_prices.1.price', '17.50000000')
            ->assertJsonPath('holdings.0.intraday_prices.2.price', '17.75000000')
            ->assertJsonPath('holdings.0.intraday_prices.3.price', '17.87000000');

        $this->assertSame(0, StockHoldingIntradayPrice::query()->where('stock_holding_id', $holding->id)->count());
        Http::assertSentCount(1);
    }

    public function test_admin_listing_does_not_fetch_eodhd_intraday_prices_when_samples_are_complete(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-05 18:10:00', 'Europe/Berlin'));
        Http::fake();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        foreach (range(0, 19) as $index) {
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-05',
                'interval' => '5m',
                'close' => number_format(470 + $index, 8, '.', ''),
                'currency' => 'EUR',
                'as_of' => Carbon::parse('2026-06-05 07:00:00', 'UTC')->addMinutes($index * 20),
                'source_name' => 'EODHD intraday',
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings?include_charts=1')
            ->assertOk()
            ->assertJsonCount(20, 'holdings.0.intraday_prices');

        Http::assertNothingSent();
    }

    public function test_admin_listing_falls_back_to_latest_price_when_session_has_no_close_quote(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 13:00:00'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '195.250000',
            'start_price' => '191.00000000',
            'end_price_24' => '191.00000000',
            'latest_price_fetched_at' => '2026-06-03 12:30:00',
            'latest_price_as_of' => '2026-06-03 12:00:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);
        // Mid-session only (Berlin 09:10 and 14:00); the market has not closed yet.
        $this->createMedianQuote($holding, '2026-06-03 07:10:00', '191.00', 'historical_session_start');
        $this->createMedianQuote($holding, '2026-06-03 12:00:00', '195.00');
        $this->createRealtimeQuote($holding, '2026-06-03 07:10:00', '191.00', 'historical_session_start');
        $this->createRealtimeQuote($holding, '2026-06-03 12:00:00', '195.25');
        $this->createEndOfDayPrice($holding, '2026-06-02', '191.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.start_price', '191.000000')
            ->assertJsonPath('holdings.0.end_price', '195.250000')
            ->assertJsonPath('holdings.0.latest_price', '195.250000')
            ->assertJsonPath('holdings.0.latest_price_trend', 'up')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '2.23');
    }

    public function test_admin_listing_falls_back_to_latest_price_when_session_has_no_quotes(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 13:00:00'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '50.000000',
            'start_price' => '50.00000000',
            'end_price' => '50.00000000',
            'end_price_24' => '50.00000000',
            'latest_price_fetched_at' => '2026-06-03 12:30:00',
            'latest_price_as_of' => '2026-06-03 12:00:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'fresh',
            'latest_price_type' => 'last',
        ]);
        $this->createEndOfDayPrice($holding, '2026-06-02', '50.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.latest_price', null)
            ->assertJsonPath('holdings.0.start_price', null)
            ->assertJsonPath('holdings.0.end_price', '50.000000')
            ->assertJsonPath('holdings.0.latest_price_trend', null)
            ->assertJsonPath('holdings.0.latest_price_change_pct', null);
    }

    public function test_admin_listing_compares_end_price_to_end24_after_exchange_close(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 18:00:00', 'Europe/Berlin'));
        $holding = StockHolding::factory()->create([
            'symbol' => 'LYXIB',
            'currency' => 'EUR',
            'latest_price' => '110.000000',
            'start_price' => '100.00000000',
            'end_price' => '120.00000000',
            'end_price_24' => '100.00000000',
            'latest_price_fetched_at' => '2026-06-04 16:00:00',
            'latest_price_as_of' => '2026-06-04 15:55:00',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            'price_status' => 'closed_market',
            'latest_price_type' => 'last',
        ]);
        $this->createRealtimeQuote($holding, '2026-06-04 07:00:00', '100.00', 'historical_session_start');
        $this->createRealtimeQuote($holding, '2026-06-04 15:55:00', '110.00');
        $this->createMedianQuote($holding, '2026-06-03 15:30:00', '120.00', 'historical_session_end');
        $this->createEndOfDayPrice($holding, '2026-06-04', '999.00');
        $this->createEndOfDayPrice($holding, '2026-06-03', '100.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.start_price', '100.000000')
            ->assertJsonPath('holdings.0.end_price', '110.000000')
            ->assertJsonPath('holdings.0.latest_price', '110.000000')
            ->assertJsonPath('holdings.0.end_price_24', '100.000000')
            ->assertJsonPath('holdings.0.end_price_24_date', '2026-06-03')
            ->assertJsonPath('holdings.0.latest_price_trend', 'up')
            ->assertJsonPath('holdings.0.latest_price_change_pct', '10.00');
    }

    public function test_admin_can_add_a_selected_stock_holding(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-02 12:00:00'));

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [
                'symbol' => 'aapl',
                'name' => 'Vanguard S&P 500 ETF',
                'isin' => 'us9229083632',
                'wkn' => 'A1JX53',
                'exchange' => 'NASDAQ',
                'mic_code' => 'xnas',
                'instrument_type' => 'ETF',
                'country' => 'United States',
                'currency' => 'usd',
            ])
            ->assertCreated()
            ->assertJsonPath('holding.symbol', 'AAPL')
            ->assertJsonPath('holding.name', 'Vanguard S&P 500 ETF')
            ->assertJsonPath('holding.isin', 'US9229083632')
            ->assertJsonPath('holding.wkn', 'A1JX53')
            ->assertJsonPath('holding.mic_code', 'XNAS')
            ->assertJsonPath('holding.currency', 'USD')
            ->assertJsonPath('holding.latest_price', null)
            ->assertJsonPath('holding.latest_price_status', 'missing')
            ->assertJsonPath('holding.latest_price_fetched_at', null)
            ->assertJsonPath('holding.latest_price_source', null)
            ->assertJsonPath('holding.latest_price_source_url', null)
            ->assertJsonPath('holding.latest_price_as_of', null)
            ->assertJsonPath('holding.trading_times', null)
            ->assertJsonPath('holding.price_type', null);

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'AAPL',
            'name' => 'Vanguard S&P 500 ETF',
            'isin' => 'US9229083632',
            'wkn' => 'A1JX53',
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
            'latest_price' => null,
            'latest_price_fetched_at' => null,
            'latest_price_source' => null,
            'latest_price_source_url' => null,
            'latest_price_as_of' => null,
            'trading_times' => null,
            'latest_price_type' => null,
        ]);
    }

    public function test_admin_can_add_a_selected_stock_holding_without_latest_price_access(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-02 13:00:00'));

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [
                'symbol' => 'exxx',
                'name' => 'iShares ATX UCITS ETF (DE)',
                'isin' => 'DE000A0D8Q23',
                'wkn' => 'A0D8Q2',
                'exchange' => 'XETRA',
                'mic_code' => 'xetr',
                'instrument_type' => 'ETF',
                'country' => 'Germany',
                'currency' => 'eur',
            ])
            ->assertCreated()
            ->assertJsonPath('holding.symbol', 'EXXX')
            ->assertJsonPath('holding.exchange', 'XETRA')
            ->assertJsonPath('holding.mic_code', 'XETR')
            ->assertJsonPath('holding.latest_price', null)
            ->assertJsonPath('holding.latest_price_status', 'missing')
            ->assertJsonPath('holding.latest_price_fetched_at', null)
            ->assertJsonPath('holding.latest_price_source', null)
            ->assertJsonPath('holding.latest_price_source_url', null)
            ->assertJsonPath('holding.trading_times', null);

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'EXXX',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'latest_price' => null,
            'latest_price_fetched_at' => null,
            'latest_price_source' => null,
            'latest_price_source_url' => null,
            'latest_price_as_of' => null,
            'trading_times' => null,
        ]);
    }

    public function test_admin_can_add_a_known_stale_eodhd_result_with_corrected_wkn(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [
                'symbol' => 'LYMH',
                'name' => 'Multi Units France - Lyxor MSCI Greece UCITS ETF',
                'isin' => 'FR0010405431',
                'exchange' => 'XETRA',
                'mic_code' => 'XETR',
                'instrument_type' => 'ETF',
                'country' => 'Germany',
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonPath('holding.name', 'Amundi MSCI Greece UCITS ETF Dist')
            ->assertJsonPath('holding.isin', 'FR0010405431')
            ->assertJsonPath('holding.wkn', 'LYX0BF');

        $this->assertDatabaseHas('stock_holdings', [
            'symbol' => 'LYMH',
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
        ]);
    }

    public function test_admin_can_queue_all_watchlist_realtime_prices(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
        ]);
        $thirdHolding = StockHolding::factory()->create([
            'symbol' => 'EXXX',
            'latest_price' => '300.000000',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '3 stock realtime price syncs started.')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.processed', 0)
            ->assertJsonPath('refresh.total', 3)
            ->assertJsonPath('refresh.step', '0/3');

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
        Queue::assertPushed(RefreshDepotHoldingPrices::class, fn (RefreshDepotHoldingPrices $job): bool => $job->refreshId === $refreshId
            && $job->recipientUserId === $admin->id);

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/refresh-prices/{$refreshId}")
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', $refreshId)
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.step', '0/3');

        $this->assertDatabaseHas('stock_holdings', [
            'id' => $thirdHolding->id,
            'latest_price' => '300.000000',
        ]);
    }

    public function test_admin_can_poll_watchlist_price_refresh_after_progress_cache_expires(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted();

        $refreshId = $response->json('refresh.refresh_id');

        Cache::forget("depot-holding-price-refresh:{$refreshId}");

        $this->assertTrue(StockPriceRefreshRun::query()->whereKey($refreshId)->exists());

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/refresh-prices/{$refreshId}")
            ->assertOk()
            ->assertJsonPath('message', '2 stock realtime price syncs queued.')
            ->assertJsonPath('refresh.refresh_id', $refreshId)
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.processed', 0)
            ->assertJsonPath('refresh.total', 2)
            ->assertJsonPath('refresh.step', '0/2');
    }

    public function test_stale_watchlist_price_refresh_without_a_queue_job_is_failed_when_polled(): void
    {
        config(['queue.default' => 'database']);

        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

        $run = StockPriceRefreshRun::query()->create([
            'id' => 'orphaned-refresh',
            'status' => 'running',
            'total_count' => 9,
            'processed_count' => 0,
            'finished_at' => null,
        ]);
        $run->update(['started_at' => now()->subHours(3)]);

        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start($run->id, 9);
        $progress->markRunning($run->id);

        $this->assertTrue($run->refresh()->started_at->lessThan(now()->subSeconds(660)));
        $this->assertSame(0, app(QueueFactory::class)->connection('database')->size('default'));

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/refresh-prices/{$run->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Price refresh failed.')
            ->assertJsonPath('refresh.refresh_id', $run->id)
            ->assertJsonPath('refresh.status', 'failed')
            ->assertJsonPath('refresh.processed', 0)
            ->assertJsonPath('refresh.total', 9)
            ->assertJsonPath('refresh.step', '0/9')
            ->assertJsonPath('refresh.error', 'Price refresh stopped because no queued or running job was found.');

        $this->assertDatabaseHas('stock_price_refresh_runs', [
            'id' => $run->id,
            'status' => 'failed',
            'processed_count' => 0,
        ]);
        $this->assertNotNull($run->refresh()->finished_at);
    }

    public function test_watchlist_price_refresh_that_processed_all_items_is_completed_when_polled(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

        $run = StockPriceRefreshRun::query()->create([
            'id' => 'processed-refresh',
            'status' => 'running',
            'total_count' => 2,
            'processed_count' => 2,
            'success_count' => 1,
            'stale_count' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start($run->id, 2);
        $progress->markRunning($run->id);

        $this->actingAs($admin)
            ->getJson("/admin/watchlist/holdings/refresh-prices/{$run->id}")
            ->assertOk()
            ->assertJsonPath('message', '2 prices refreshed.')
            ->assertJsonPath('refresh.refresh_id', $run->id)
            ->assertJsonPath('refresh.status', 'partial')
            ->assertJsonPath('refresh.processed', 2)
            ->assertJsonPath('refresh.total', 2)
            ->assertJsonPath('refresh.step', '2/2');

        $this->assertDatabaseHas('stock_price_refresh_runs', [
            'id' => $run->id,
            'status' => 'partial',
            'processed_count' => 2,
        ]);
        $this->assertNotNull($run->refresh()->finished_at);
    }

    public function test_admin_can_queue_watchlist_realtime_prices_without_an_active_depot(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'EXXX',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '1 stock realtime price sync started.')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 1);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
    }

    public function test_manual_watchlist_realtime_queue_does_not_update_index_schedule(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings/refresh-prices')
            ->assertAccepted()
            ->assertJsonPath('message', '1 stock realtime price sync started.')
            ->assertJsonPath('refresh.total', 1)
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('index_price_refresh_settings.status', 'waiting')
            ->assertJsonPath('index_price_refresh_settings.status_label', 'waiting');
    }

    public function test_legacy_queued_job_uses_the_batch_realtime_sync_and_tracks_progress(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Mail::fake();
        $apple = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'wkn' => '865985',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'instrument_type' => 'Common Stock',
            'country' => 'United States',
            'currency' => 'USD',
            'latest_price' => '100.000000',
        ]);
        $microsoft = StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corporation',
            'isin' => 'US5949181045',
            'wkn' => '870747',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'instrument_type' => 'Common Stock',
            'country' => 'United States',
            'currency' => 'USD',
            'latest_price' => '200.000000',
            'latest_price_fetched_at' => '2026-06-02 14:30:00',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/msft',
            'latest_price_as_of' => '2026-06-02 14:29 UTC',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/*' => Http::response([
                [
                    'code' => 'AAPL.US',
                    'timestamp' => Carbon::parse('2026-06-02 15:00:00', 'UTC')->timestamp,
                    'close' => 306.320010,
                ],
                [
                    'code' => 'MSFT.US',
                    'timestamp' => Carbon::parse('2026-06-02 15:00:00', 'UTC')->timestamp,
                    'close' => 406.120000,
                ],
            ]),
        ]);
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-test', 2);

        (new RefreshDepotHoldingPrices('refresh-test'))->handle(
            app(EodhdBatchRealtimePriceService::class),
            $progress,
        );

        $this->assertDatabaseHas('stock_realtime_prices', [
            'stock_holding_id' => $apple->id,
            'source_key' => 'eodhd_realtime',
            'price' => '306.32001000',
        ]);
        $this->assertDatabaseHas('stock_holdings', [
            'id' => $microsoft->id,
            'currency' => 'USD',
            'latest_price' => '200.000000',
            'latest_price_fetched_at' => '2026-06-02 14:30:00',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/msft',
            'latest_price_as_of' => '2026-06-02 14:29 UTC',
        ]);
        $this->assertSame('finished', $progress->get('refresh-test')['status']);
        $this->assertSame('2/2', $progress->get('refresh-test')['step']);
    }

    public function test_legacy_queued_job_does_not_refresh_index_prices(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/real-time/*' => Http::response([]),
        ]);
        $index = IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'name' => 'Austrian Traded Index in EUR',
            'isin' => 'AT0000999982',
            'exchange' => 'INDX',
            'country' => 'Austria',
            'currency' => 'EUR',
        ]);
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-index-test', 1);

        (new RefreshDepotHoldingPrices('refresh-index-test'))->handle(
            app(EodhdBatchRealtimePriceService::class),
            $progress,
        );

        $this->assertDatabaseHas('index_watch_items', [
            'id' => $index->id,
            'latest_price' => null,
            'latest_price_source' => null,
        ]);
        $this->assertDatabaseMissing('index_watch_item_prices', [
            'index_watch_item_id' => $index->id,
        ]);
        $this->assertSame('finished', $progress->get('refresh-index-test')['status']);
        $this->assertSame('0/0', $progress->get('refresh-index-test')['step']);
    }

    public function test_completed_manual_refresh_does_not_send_watchlist_pdf_email(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Mail::fake();
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'MAILPDF',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/*' => Http::response([
                'code' => 'MAILPDF.US',
                'timestamp' => Carbon::parse('2026-06-05 10:00:00', 'UTC')->timestamp,
                'close' => 101.12,
            ]),
        ]);
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-mail-test', 1);

        (new RefreshDepotHoldingPrices('refresh-mail-test', $admin->id))->handle(
            app(EodhdBatchRealtimePriceService::class),
            $progress,
        );

        Mail::assertNothingOutgoing();
    }

    public function test_refresh_still_finishes_without_report_email_recipient(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'MAILFAIL',
            'currency' => 'EUR',
            'price_status' => 'fresh',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/*' => Http::response([
                'code' => 'MAILFAIL.US',
                'timestamp' => Carbon::parse('2026-06-05 10:00:00', 'UTC')->timestamp,
                'close' => 101.12,
            ]),
        ]);
        Mail::fake();
        $progress = app(DepotHoldingPriceRefreshProgress::class);
        $progress->start('refresh-mail-fail-test', 1);

        (new RefreshDepotHoldingPrices('refresh-mail-fail-test', $admin->id))->handle(
            app(EodhdBatchRealtimePriceService::class),
            $progress,
        );

        $this->assertSame('finished', $progress->get('refresh-mail-fail-test')['status']);
        $this->assertSame('1/1', $progress->get('refresh-mail-fail-test')['step']);
        Mail::assertNothingOutgoing();
    }

    public function test_admin_must_provide_a_selected_symbol(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/watchlist/holdings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('symbol');
    }

    public function test_admin_can_update_a_holding_flatex_price(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'isin' => null,
            'wkn' => null,
            'latest_price' => '191.500000',
            'flatex_price' => '191.500000',
        ]);
        $matchingHolding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'isin' => null,
            'wkn' => null,
            'latest_price' => '190.000000',
            'flatex_price' => '190.000000',
        ]);
        $otherHolding = StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'isin' => null,
            'wkn' => null,
            'flatex_price' => '300.000000',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/watchlist/holdings/{$holding->id}/flatex-price", [
                'flatex_price' => '180.250000',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Flatex price updated.')
            ->assertJsonPath('holding.id', $holding->id)
            ->assertJsonPath('holding.flatex_price', '180.250000')
            ->assertJsonCount(2, 'holdings')
            ->assertJsonPath('holdings.0.id', $holding->id)
            ->assertJsonPath('holdings.0.flatex_price', '180.250000')
            ->assertJsonPath('holdings.1.id', $matchingHolding->id)
            ->assertJsonPath('holdings.1.flatex_price', '180.250000');

        $this->assertSame('180.250000', $holding->refresh()->flatex_price);
        $this->assertSame('180.250000', $matchingHolding->refresh()->flatex_price);
        $this->assertSame('300.000000', $otherHolding->refresh()->flatex_price);
    }

    public function test_admin_must_provide_a_valid_flatex_price(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'flatex_price' => '191.500000',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/watchlist/holdings/{$holding->id}/flatex-price", [
                'flatex_price' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('flatex_price');

        $this->assertSame('191.500000', $holding->refresh()->flatex_price);
    }

    public function test_admin_can_delete_a_watchlist_holding(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/watchlist/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Stock deleted.');

        $this->assertModelMissing($holding);
    }

    public function test_admin_can_delete_a_watchlist_holding_without_an_active_depot(): void
    {
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/watchlist/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Stock deleted.');

        $this->assertModelMissing($holding);
    }

    public function test_admin_can_list_watchlist_holdings_without_an_active_depot(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('depot', null)
            ->assertJsonPath('holdings.0.symbol', 'AAPL');
    }

    public function test_admin_can_export_watchlist_pdf(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'UTC'));

        $holding = StockHolding::factory()->create([
            'symbol' => 'PDFCO',
            'currency' => 'EUR',
            'price_status' => 'fresh',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        $latestPrice = $this->createMedianQuote($holding, '2026-06-03 17:00:00', '101.00');
        $holding->update(['latest_stock_price_id' => $latestPrice->id]);

        $response = $this->actingAs($admin)->get('/admin/watchlist/holdings/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('watch-list-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_admin_can_export_watchlist_pdf_without_holdings(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get('/admin/watchlist/holdings/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_guest_cannot_manage_holdings(): void
    {
        $this->getJson('/admin/watchlist/holdings')->assertUnauthorized();
        $this->getJson('/admin/watchlist/exchange-trading-times')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/1/realtime-prices/latest')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/1/intraday-candles/latest-days')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/1/end-of-day-prices/latest-days')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/pdf')->assertUnauthorized();
        $this->postJson('/admin/watchlist/holdings', ['symbol' => 'AAPL'])->assertUnauthorized();
        $this->postJson('/admin/watchlist/holdings/refresh-prices')->assertUnauthorized();
        $this->getJson('/admin/watchlist/holdings/refresh-prices/example')->assertUnauthorized();
        $this->patchJson('/admin/watchlist/holdings/1/flatex-price', ['flatex_price' => '180.25'])->assertUnauthorized();
        $this->deleteJson('/admin/watchlist/holdings/1')->assertUnauthorized();
    }

    private function createMedianQuote(StockHolding $holding, string $asOf, string $price, string $priceType = 'last'): StockPrice
    {
        return StockPrice::query()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|{$asOf}|{$price}|{$priceType}"),
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://example.com',
            'source_quality' => 'market_data_vendor',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'price_type' => $priceType,
            'as_of' => Carbon::parse($asOf, 'UTC'),
            'fetched_at' => Carbon::parse($asOf, 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
        ]);
    }

    private function createRealtimeQuote(StockHolding $holding, string $asOf, string $price, string $priceType = 'last'): StockRealtimePrice
    {
        return StockRealtimePrice::query()->create([
            'stock_holding_id' => $holding->id,
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|realtime|{$asOf}|{$price}|{$priceType}"),
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://example.com',
            'source_quality' => 'market_data_vendor',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'price' => $price,
            'price_type' => $priceType,
            'as_of' => Carbon::parse($asOf, 'UTC'),
            'fetched_at' => Carbon::parse($asOf, 'UTC'),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
        ]);
    }

    private function createIntradayCandle(StockHolding $holding, string $tradingDate, string $asOf, string $price): StockHoldingIntradayCandle
    {
        return StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'interval' => '5m',
            'as_of' => Carbon::parse($asOf, 'UTC'),
            'close' => $price,
            'currency' => $holding->currency,
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
        ]);
    }

    private function createEndOfDayPrice(StockHolding $holding, string $tradingDate, string $price): StockPrice
    {
        return StockPrice::query()->create([
            'instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'quote_hash' => hash('sha256', "{$holding->id}|eod|{$tradingDate}|{$price}"),
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'source_url' => 'https://example.com/eod',
            'source_quality' => 'official_venue',
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'symbol' => $holding->symbol,
            'currency' => $holding->currency,
            'close' => $price,
            'price' => $price,
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse($tradingDate, 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse($tradingDate, 'Europe/Vienna')->endOfDay()->utc(),
            'freshness_status' => 'historical',
            'validation_status' => 'valid',
        ]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
