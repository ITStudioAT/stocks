<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Services\FreeTradingCalendar;
use App\Services\MarketDataCoordinator;
use App\Services\MarketPriceCompatibility;
use App\Services\MarketPriceStore;
use App\Services\XetraIntradayCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class MarketDataCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_reports_when_another_collector_holds_the_lock(): void
    {
        $lock = Cache::lock('market-data.coordinator', 60);
        $this->assertTrue($lock->get());
        try {
            $result = app(MarketDataCoordinator::class)->collect();
            $this->assertSame(['Another coordinated collection is running.'], $result['errors']);
        } finally {
            $lock->release();
        }
    }

    public function test_five_minute_gate_and_calendar_handle_dst_holidays_early_close_and_bounded_tail(): void
    {
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        $calendar = app(FreeTradingCalendar::class);
        $this->assertTrue($calendar->shouldCollect($holding, Carbon::parse('2026-03-20 13:30:00', 'UTC')));
        $this->assertFalse($calendar->shouldCollect($holding, Carbon::parse('2026-03-06 13:30:00', 'UTC')));
        $this->assertFalse($calendar->shouldCollect($holding, Carbon::parse('2026-11-26 16:00:00', 'UTC')));
        $this->assertSame('18:00', $calendar->session($holding, Carbon::parse('2026-11-27', 'America/New_York'))['close']->format('H:i'));
        $this->assertTrue($calendar->shouldCollect($holding, Carbon::parse('2026-11-27 18:25:00', 'UTC')));
        $this->assertFalse($calendar->shouldCollect($holding, Carbon::parse('2026-11-27 18:31:00', 'UTC')));
        $this->assertNull($calendar->session($holding, Carbon::parse('2029-01-03', 'America/New_York')));
        $this->travelTo(Carbon::parse('2026-11-06 16:00', 'UTC'));
        $coordinator = app(MarketDataCoordinator::class);
        $this->assertTrue($coordinator->reserve('test'));
        $this->travel(299)->seconds();
        $this->assertFalse($coordinator->reserve('test'));
        $this->travel(1)->seconds();
        $this->assertTrue($coordinator->reserve('test'));
    }

    public function test_saved_duplicate_listings_make_only_one_request_and_old_schedules_are_disabled(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-06 16:00:00', 'UTC'));
        StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        $this->fakeYahooMarketData(['AAPL' => ['price' => 200, 'at' => '2026-11-06 15:50:00']]);
        $coordinator = app(MarketDataCoordinator::class);
        $first = $coordinator->collect();
        $this->assertSame(2, $first['stored_count']);
        Http::assertSentCount(1);
        $this->assertSame(0, $coordinator->collect()['stored_count']);
        Http::assertSentCount(1);
        $this->travel(5)->minutes();
        $coordinator->collect();
        Http::assertSentCount(2);
        $events = collect(Schedule::events());
        foreach (['intraday:collect-free', 'price-refresh:dispatch-due', 'intraday-candles:dispatch-due'] as $command) {
            $this->assertFalse($events->first(fn ($event) => str_contains($event->command ?? '', $command))->filtersPass(app()));
        }
        $new = $events->first(fn ($event) => str_contains($event->command ?? '', 'market-data:collect'));
        $this->assertSame('*/5 * * * *', $new->expression);
        $this->assertTrue(MarketPriceStore::enabled());
    }

    public function test_scheduled_collection_prepares_reads_outside_market_hours_without_provider_calls(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        app(MarketDataCoordinator::class)->persist($holding, [[
            'source_key' => 'yahoo_intraday', 'currency' => 'USD', 'price' => 200, 'as_of' => '2026-11-06 20:58:00',
        ]]);
        $this->mock(MarketPriceCompatibility::class, function ($mock): void {
            $mock->shouldReceive('history')->twice()->andReturn([]);
        });
        $this->artisan('market-data:collect')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_collection_recovers_a_missed_session_after_market_close_and_throttles_retries(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-10 06:00:00', 'UTC'));
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        $this->fakeYahooMarketData(['AAPL' => ['price' => 210, 'at' => '2026-11-09 20:58:00']]);

        $coordinator = app(MarketDataCoordinator::class);
        $result = $coordinator->collect();

        $this->assertGreaterThan(0, $result['stored_count']);
        $this->assertDatabaseHas('market_prices', ['market_listing_id' => $holding->refresh()->market_listing_id, 'trading_date' => '2026-11-09']);
        Http::assertSentCount(1);
        $this->travel(5)->minutes();
        $coordinator->collect();
        Http::assertSentCount(1);
    }

    public function test_failed_recovery_is_rate_limited_outside_market_hours(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-10 06:00:00', 'UTC'));
        StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        Http::fake(['query1.finance.yahoo.com/*' => Http::response([], 500)]);

        $coordinator = app(MarketDataCoordinator::class);
        $coordinator->collect();
        $this->travel(5)->minutes();
        $coordinator->collect();
        Http::assertSentCount(1);
        $this->travel(25)->minutes();
        $coordinator->collect();
        Http::assertSentCount(2);
    }

    public function test_collection_recovers_an_incomplete_latest_session_after_close(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-10 06:00:00', 'UTC'));
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'exchange' => 'NASDAQ', 'currency' => 'USD', 'mic_code' => 'XNAS']);
        $coordinator = app(MarketDataCoordinator::class);
        $coordinator->persist($holding, [['source_key' => 'yahoo_intraday', 'currency' => 'USD', 'price' => 200, 'as_of' => '2026-11-09 20:45:00']]);
        $this->fakeYahooMarketData(['AAPL' => ['price' => 210, 'at' => '2026-11-09 20:58:00']]);

        $coordinator->collect();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('market_prices', ['market_listing_id' => $holding->refresh()->market_listing_id, 'trading_date' => '2026-11-09', 'price' => '210']);
    }

    public function test_xetra_supplement_uses_verified_provider_symbol_for_its_shared_gate(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'UTC'));
        $holding = StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'CUSTOM', 'exchange' => 'XETRA', 'currency' => 'EUR', 'mic_code' => 'XETR']);
        $this->mock(XetraIntradayCollector::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andReturn(['series' => [], 'warnings' => [], 'days' => [], 'complete' => true]);
        });
        $coordinator = app(MarketDataCoordinator::class);
        $this->assertTrue($coordinator->reserve('yahoo-symbol:TKE.DE'));
        $this->assertSame(0, $coordinator->collect($holding)['stored_count']);
        Http::assertNothingSent();
    }
}
