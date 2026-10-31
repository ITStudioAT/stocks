<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\UsMarketDataTest as UsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 22:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    public function test_argt_is_displayed_as_a_us_market_quote_with_stale_database_comparison_and_no_writes(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($user);
        $holding = StockHolding::factory()->create($this->instrument());
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 89.82,
            'as_of' => '2026-06-26 07:00:00', 'currency' => 'USD', 'venue' => 'US', 'mic' => null, 'price_type' => 'historical_session_start']);
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->payload())]);
        $this->postJson('/admin/dashboard/tests/deutsche-boerse', ['batch' => 9])->assertOk()
            ->assertJsonPath('test.instruments.0.price', '79.18')->assertJsonPath('test.instruments.0.currency', 'USD')
            ->assertJsonPath('test.instruments.0.trade_at', '2026-10-30T20:00:00.000000Z')
            ->assertJsonPath('test.instruments.0.trade_source', 'Yahoo Finance')->assertJsonPath('test.instruments.0.listing_mic', 'ARCX')
            ->assertJsonPath('test.instruments.0.trade_venue', null)->assertJsonPath('test.instruments.0.price_type', 'regular_market_last')
            ->assertJsonPath('test.instruments.0.stored_quote.stale', true)->assertJsonPath('test.instruments.0.stored_quote.price', '89.82000000')
            ->assertJsonPath('test.instruments.0.comparison.comparable', false)->assertJsonPath('test.instruments.0.comparison.value_status', 'different');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('stock_realtime_prices', 1);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
        $this->assertDatabaseCount('stock_holding_daily_prices', 0);
    }

    public function test_missing_future_currency_and_identity_mismatches_do_not_become_quotes(): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()
            ->push($this->payload(['regularMarketPrice' => null]))
            ->push($this->payload(['regularMarketTime' => now()->addDay()->timestamp]))
            ->push($this->payload(['currency' => 'EUR']))
            ->push($this->payload(['symbol' => 'WRONG']))
            ->push($this->payload(['exchangeName' => 'LSE']))]);
        foreach (range(1, 5) as $attempt) {
            $result = app(UsProbe::class)->run(collect([$this->instrument()]));
            $this->assertNull($result['instruments'][0]['price']);
            $this->assertSame('partial', $result['status']);
        }
        Http::assertSentCount(5);
    }

    public function test_old_source_value_retains_its_real_time_and_is_marked_stale(): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->payload(['regularMarketTime' => now()->subDays(10)->timestamp]))]);
        $result = app(UsProbe::class)->run(collect([$this->instrument()]));
        $this->assertTrue($result['instruments'][0]['quote_stale']);
        $this->assertStringContainsString('Veralteter Quellwert', $result['instruments'][0]['quote_note']);
        $this->assertSame('79.18', $result['instruments'][0]['price']);
    }

    public function test_429_and_403_stop_all_other_us_downloads_without_retry(): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()->push('', 429)->push('', 403)]);
        foreach ([429, 403] as $status) {
            Cache::forget('free-intraday.cooldown.yahoo');
            $result = app(UsProbe::class)->run(collect([$this->instrument(), $this->instrument('AAPL'), $this->instrument('MSFT')]));
            $this->assertTrue($result['provider_blocked']);
            $this->assertSame(1, $result['coverage'][0]['requests']);
            $this->assertCount(1, $result['warnings']);
            $this->assertStringContainsString('HTTP '.$status, $result['warnings'][0]);
        }
        Http::assertSentCount(2);
    }

    public function test_us_coverage_is_bounded_and_supports_other_us_tickers(): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->payload(['symbol' => 'AAPL', 'exchangeName' => 'NMS', 'instrumentType' => 'EQUITY']))]);
        $result = app(UsProbe::class)->run(collect(array_fill(0, 5, $this->instrument('AAPL'))));
        $this->assertSame('found', $result['instruments'][0]['state']);
        $this->assertSame(3, $result['coverage'][0]['requests']);
        $this->assertNull($result['instruments'][4]['price']);
        Http::assertSentCount(3);
    }

    public function test_non_us_instrument_does_not_trigger_yahoo_requests(): void
    {
        $item = [...$this->instrument(), 'isin' => 'AT0000606306', 'exchange' => 'XETRA', 'currency' => 'EUR'];
        $result = app(UsProbe::class)->run(collect([$item]));
        $this->assertSame('not_applicable', $result['instruments'][0]['state']);
        Http::assertNothingSent();
    }

    public function test_five_minute_ohlc_filters_empty_invalid_and_out_of_session_bars_without_filling_gaps(): void
    {
        $payload = $this->payload(['dataGranularity' => '5m']);
        $times = ['2026-10-30 13:25', '2026-10-30 13:30', '2026-10-30 13:35', '2026-10-30 13:40', '2026-10-30 20:00'];
        $payload['chart']['result'][0]['timestamp'] = array_map(fn (string $at): int => Carbon::parse($at, 'UTC')->timestamp, $times);
        $payload['chart']['result'][0]['indicators']['quote'][0] = ['open' => [79, 79, null, 79, 79],
            'high' => [80, 80, null, 78, 80], 'low' => [78, 78, null, 78, 78], 'close' => [79, 79.5, null, 79, 79], 'volume' => [1, 2, 0, 4, 5]];
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($payload)]);
        $result = app(UsProbe::class)->run(collect([$this->instrument()]), includeSeries: true);
        $this->assertCount(1, $result['instruments'][0]['series']);
        $this->assertSame(2, $result['instruments'][0]['series_gap_count']);
        $this->assertSame('partial', $result['status']);
        $bar = $result['instruments'][0]['series'][0];
        $this->assertSame('79.50000000', $bar['close']);
        $this->assertSame('provider_ohlc', $bar['data_kind']);
        $this->assertSame('USD', $bar['currency']);
        $this->assertSame(Carbon::parse($times[1], 'UTC')->timestamp, $bar['timestamp']);
    }

    public function test_us_session_follows_new_york_dst_and_rejects_an_unexpected_bar_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 22:00:00', 'UTC'));
        $payload = $this->payload(['dataGranularity' => '5m', 'regularMarketTime' => now()->subHour()->timestamp]);
        $payload['chart']['result'][0]['timestamp'] = [Carbon::parse('2026-11-02 13:30', 'UTC')->timestamp, Carbon::parse('2026-11-02 14:30', 'UTC')->timestamp];
        $payload['chart']['result'][0]['indicators']['quote'][0] = ['open' => [79, 79], 'high' => [80, 80], 'low' => [78, 78], 'close' => [79.1, 79.2]];
        $wrong = $payload;
        $wrong['chart']['result'][0]['meta']['dataGranularity'] = '1h';
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()->push($payload)->push($wrong)]);
        $result = app(UsProbe::class)->run(collect([$this->instrument()]), includeSeries: true);
        $this->assertCount(1, $result['instruments'][0]['series']);
        $this->assertSame('79.20000000', $result['instruments'][0]['series'][0]['close']);
        $result = app(UsProbe::class)->run(collect([$this->instrument()]), includeSeries: true);
        $this->assertSame('partial', $result['status']);
        $this->assertArrayNotHasKey('series', $result['instruments'][0]);
    }

    public function test_malformed_oversized_or_missing_metadata_cannot_fall_back_to_an_unrelated_candle(): void
    {
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()->push('not-json')
            ->push(str_repeat('x', 524289))->push(['chart' => ['result' => [['indicators' => ['quote' => [['close' => [99]]]]]]]])]);
        foreach (range(1, 3) as $attempt) {
            $result = app(UsProbe::class)->run(collect([$this->instrument()]));
            $this->assertNull($result['instruments'][0]['price']);
            $this->assertSame('partial', $result['status']);
        }
        Http::assertSentCount(3);
    }

    /** @return array<string, mixed> */
    private function instrument(string $symbol = 'ARGT'): array
    {
        return ['isin' => $symbol === 'ARGT' ? 'US37950E2596' : 'US0378331005', 'symbol' => $symbol,
            'name' => 'US ETF / Equity', 'exchange' => 'US', 'mic_code' => null, 'currency' => 'USD'];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['chart' => ['result' => [['meta' => [...[
            'symbol' => 'ARGT', 'currency' => 'USD', 'exchangeName' => 'PCX', 'fullExchangeName' => 'NYSEArca',
            'exchangeTimezoneName' => 'America/New_York', 'instrumentType' => 'ETF', 'regularMarketPrice' => 79.18,
            'regularMarketTime' => Carbon::parse('2026-10-30 20:00:00', 'UTC')->timestamp,
        ], ...$overrides]]], 'error' => null]];
    }
}
