<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Services\FreeTradingCalendar;
use App\Services\StockPreviousCloseResolver;
use App\Services\StockPriceCatalog;
use App\Services\WatchlistPdfReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class FreePreviousTradingDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    public function test_it_uses_the_last_actual_free_observation_across_quotes_and_candles_without_paid_fallback(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-03 14:00:00', 100);
        $this->quote($holding, '2026-11-03 16:29:00', 105);
        $this->quote($holding, '2026-11-03 16:31:00', 999, 'eodhd_realtime');
        StockHoldingIntradayCandle::query()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-03',
            'as_of' => '2026-11-03 16:30:00', 'interval' => 'xtrade', 'close' => 106, 'currency' => 'EUR',
            'source_key' => 'xetra_posttrade', 'raw_payload' => ['mic' => 'XETR']]);
        StockPrice::factory()->create(['instrument_key' => app(StockPriceCatalog::class)->instrumentKeyForHolding($holding),
            'source_key' => 'eodhd_eod', 'price_type' => 'historical_eod', 'price' => 777, 'as_of' => '2026-11-03 17:00:00']);

        $resolver = app(StockPreviousCloseResolver::class);
        $result = $resolver->resolve($holding);
        $this->assertSame('106.00000000', $result['previous_close']);
        $this->assertSame('2026-11-03', $result['previous_close_date']);
        $this->assertSame(['free'], $result['previous_close_source_markers']);
        $this->assertNull($result['earlier_close']);
        $resolver->prime(StockHolding::query()->whereKey($holding->id)->get());
        $resolver->prime(collect([$holding]));
        $this->assertSame($result, $resolver->resolve($holding));
        $this->assertSame('106.00000000', $resolver->priceAt($holding, now()->subDay()));
        Http::assertNothingSent();
    }

    public function test_missing_previous_day_is_not_replaced_by_older_free_or_paid_prices(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-02 16:30:00', 100);
        $this->quote($holding, '2026-11-03 16:30:00', 200, 'eodhd_realtime');

        $result = app(StockPreviousCloseResolver::class)->resolve($holding);

        $this->assertNull($result['previous_close']);
        $this->assertSame('2026-11-03', $result['previous_close_date']);
        $this->assertSame([], $result['previous_close_source_markers']);
        $this->assertSame('100.00000000', $result['earlier_close']);
    }

    public function test_it_ignores_wrong_currency_and_quarantined_observations(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-03 15:00:00', 100);
        $this->quote($holding, '2026-11-03 16:00:00', 200)->update(['currency' => 'USD']);
        $this->quote($holding, '2026-11-03 16:30:00', 300)->update(['raw_payload' => ['quarantined' => true]]);

        $this->assertSame('100.00000000', app(StockPreviousCloseResolver::class)->resolve($holding)['previous_close']);
        app(StockPreviousCloseResolver::class)->prime(collect([$holding]));
        $this->assertSame('100.00000000', app(StockPreviousCloseResolver::class)->resolve($holding)['previous_close']);
    }

    public function test_pdf_export_uses_the_same_missing_reference_as_the_current_views(): void
    {
        $holding = $this->holding();
        $this->quote($holding, '2026-11-04 12:00:00', 105);
        $this->quote($holding, '2026-11-03 16:30:00', 999, 'eodhd_realtime');
        $rendered = false;
        View::composer('pdf.watchlist', function ($view) use (&$rendered): void {
            $rendered = true;
            $row = $view->getData()['holdings'][0];
            $this->assertSame('105.000000', $row['latest_price']);
            $this->assertNull($row['end_price_24']);
            $this->assertNull($row['latest_price_change_pct']);
            $this->assertSame([], $row['end_price_24_source_markers']);
        });

        $report = app(WatchlistPdfReport::class)->render();

        $this->assertTrue($rendered);
        $this->assertStringStartsWith('%PDF-', $report['content']);
        Http::assertNothingSent();
    }

    public function test_calendar_skips_weekends_and_exchange_specific_holidays(): void
    {
        $calendar = app(FreeTradingCalendar::class);
        $holding = $this->holding();
        $this->assertSame('2026-04-02', $calendar->previousDay($holding, Carbon::parse('2026-04-07', 'Europe/Berlin'))?->toDateString());
        $this->assertSame('2026-05-22', $calendar->previousDay($holding, Carbon::parse('2026-05-25', 'Europe/Berlin'))?->toDateString());
        $us = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS']);
        $this->assertSame('2026-07-02', $calendar->previousDay($us, Carbon::parse('2026-07-06', 'America/New_York'))?->toDateString());
        $this->assertSame('2026-05-22', $calendar->previousDay($us, Carbon::parse('2026-05-26', 'America/New_York'))?->toDateString());
        $this->assertNull($calendar->previousDay($holding, Carbon::parse('2030-01-10', 'Europe/Berlin')));
    }

    public function test_us_reference_uses_the_venue_date_across_midnight_and_dst(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 00:30:00', 'UTC'));
        $holding = StockHolding::factory()->create(['isin' => 'US0378331005', 'symbol' => 'AAPL', 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS']);
        $this->quote($holding, '2026-11-02 21:00:00', 120, 'yahoo_intraday')->update(['currency' => 'USD']);
        $this->quote($holding, '2026-11-03 21:00:00', 130, 'yahoo_intraday')->update(['currency' => 'USD']);

        $result = app(StockPreviousCloseResolver::class)->resolve($holding);

        $this->assertSame('2026-11-02', $result['previous_close_date']);
        $this->assertSame('120.00000000', $result['previous_close']);
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['isin' => 'DE0008404005', 'symbol' => 'ALV', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
    }

    private function quote(StockHolding $holding, string $asOf, int $price, string $source = 'xetra_posttrade'): StockRealtimePrice
    {
        return StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'source_key' => $source,
            'price' => $price, 'currency' => $holding->currency, 'as_of' => $asOf, 'mic' => $holding->mic_code]);
    }
}
