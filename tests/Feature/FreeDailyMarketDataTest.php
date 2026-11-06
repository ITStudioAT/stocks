<?php

namespace Tests\Feature;

use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\FreeDailyMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreeDailyMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.market_data.central_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-06 18:00:00', 'UTC'));
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['isin' => 'US8740541094', 'symbol' => 'TKE', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
    }

    private function chart(string $currency = 'EUR'): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['symbol' => 'TKE.DE', 'currency' => $currency, 'exchangeName' => 'GER', 'fullExchangeName' => 'XETRA',
                'exchangeTimezoneName' => 'Europe/Berlin', 'instrumentType' => 'EQUITY', 'dataGranularity' => '1d'],
            'timestamp' => [Carbon::parse('2026-11-05 08:00:00', 'UTC')->timestamp, Carbon::parse('2026-11-06 08:00:00', 'UTC')->timestamp],
            'indicators' => ['quote' => [['open' => [190.2, null], 'high' => [194.0, null], 'low' => [189.5, null],
                'close' => [193.99000549316406, null], 'volume' => [1614, null]]], 'adjclose' => [['adjclose' => [192.25, null]]]],
        ]]]];
    }

    public function test_daily_import_preserves_real_semantics_skips_gaps_and_is_repeatable(): void
    {
        $holding = $this->holding();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart())]);
        $daily = app(FreeDailyMarketData::class);
        $first = $daily->import($holding);
        $this->assertSame(1, $first['stored_count']);
        $this->assertSame(1, $first['skipped_count']);
        $row = MarketPrice::sole();
        $this->assertSame('bar', $row->record_kind);
        $this->assertSame('1d', $row->interval_code);
        $this->assertNull($row->observed_at);
        $this->assertSame('session_close_derived', $row->time_basis);
        $this->assertNotSame($row->close, $row->adjusted_close);
        $this->assertSame('2026-11-05', $row->trading_date);
        $this->assertSame('cooldown', $daily->import($holding)['status']);
        Http::assertSentCount(1);
        $this->travel(5)->minutes();
        $this->assertSame(0, $daily->import($holding)['stored_count']);
        $this->assertSame(1, MarketPrice::count());
    }

    public function test_rate_limit_blocks_later_calls_and_wrong_currency_never_enters_storage(): void
    {
        $holding = $this->holding();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response('', 429, ['Retry-After' => '1800'])]);
        $daily = app(FreeDailyMarketData::class);
        $this->assertSame('failed', $daily->import($holding)['status']);
        $this->travel(6)->minutes();
        $this->assertSame('cooldown', $daily->import($holding)['status']);
        Http::assertSentCount(1);
        $this->assertSame(0, MarketPrice::count());
        $this->travel(25)->minutes();
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart('USD'))]);
        $this->assertSame('failed', $daily->import($holding)['status']);
        $this->assertSame(0, MarketPrice::count());
    }

    public function test_daily_endpoint_requires_admin_and_new_holdings_have_a_listing_without_fetching(): void
    {
        $holding = $this->holding();
        $this->assertNotNull($holding->market_listing_id);
        $this->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertForbidden();
        $user->assignRole(Role::findOrCreate('admin'));
        Http::fake(['query1.finance.yahoo.com/*' => Http::response($this->chart())]);
        $this->postJson('/admin/watchlist/holdings/'.$holding->id.'/daily-history')->assertOk()->assertJsonPath('stored_count', 1);
        $this->assertSame(1, MarketPrice::count());
    }
}
