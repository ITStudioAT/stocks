<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\User;
use App\Services\FreeIntradayMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HoldingPriceReloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'Europe/Vienna'));
        Http::preventStrayRequests();
        config(['services.eodhd.enabled' => false]);
    }

    public function test_reload_checks_the_free_feed_again_and_only_updates_the_selected_stock(): void
    {
        $holding = $this->holding();
        $other = StockHolding::factory()->create(['symbol' => 'CZX', 'isin' => 'BGCZPX003174', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $this->fakeEuropeanTrades([
            ['holding' => $holding, 'price' => 17.64, 'at' => '2026-11-04 08:04:40'],
            ['holding' => $other, 'price' => 2.3165, 'at' => '2026-11-04 08:10:22'],
        ]);
        $this->actingAs($this->admin());
        $url = "/admin/watchlist/holdings/{$holding->id}/price/reload";
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'updated')
            ->assertJsonPath('holding.latest_price', '17.640000')
            ->assertJsonPath('holding.latest_price_as_of', '2026-11-04T09:04:40+01:00');
        $catalogRequests = Http::recorded(fn ($request): bool => $request->url() === 'https://mfs.deutsche-boerse.com/api/DETR-posttrade')->count();
        $this->travelTo(now()->addMinute());
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('holding.latest_price_as_of', '2026-11-04T09:04:40+01:00')
            ->assertJsonPath('checked_at', '2026-11-04T10:01:00+01:00');
        $this->assertSame($catalogRequests + 1, Http::recorded(fn ($request): bool => $request->url() === 'https://mfs.deutsche-boerse.com/api/DETR-posttrade')->count());
        $this->assertDatabaseCount('stock_realtime_prices', 1);
        $this->assertDatabaseMissing('stock_realtime_prices', ['stock_holding_id' => $other->id]);
        $this->assertDatabaseMissing('stock_holding_intraday_candles', ['stock_holding_id' => $other->id]);
        $this->assertNull($other->fresh()->latest_realtime_price_id);
        $this->assertDatabaseCount('stock_price_refresh_runs', 0);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_a_new_trade_at_the_same_price_is_reported_as_new_source_data(): void
    {
        $holding = $this->holding();
        $this->actingAs($this->admin());
        $this->fakeEuropeanTrades([['holding' => $holding, 'price' => 17.64, 'at' => '2026-11-04 08:04:40']]);
        $url = "/admin/watchlist/holdings/{$holding->id}/price/reload";
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'updated');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeEuropeanTrades([['holding' => $holding, 'price' => 17.64, 'at' => '2026-11-04 08:10:22']]);
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'updated')
            ->assertJsonPath('holding.latest_price', '17.640000')
            ->assertJsonPath('holding.latest_price_as_of', '2026-11-04T09:10:22+01:00');
    }

    public function test_provider_failure_retains_the_price_and_does_not_use_a_paid_fallback(): void
    {
        $holding = $this->holding();
        $this->fakeEuropeanTrades([['holding' => $holding, 'price' => 17.64, 'at' => '2026-11-04 08:04:40']]);
        app(FreeIntradayMarketData::class)->syncHolding($holding);
        $oldQuote = $holding->fresh()->latest_realtime_price_id;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://mfs.deutsche-boerse.com/*' => Http::response('', 429)]);
        $this->actingAs($this->admin())->postJson("/admin/watchlist/holdings/{$holding->id}/price/reload")
            ->assertOk()->assertJsonPath('status', 'incomplete')->assertJsonPath('holding.latest_price', '17.640000');
        $this->assertSame($oldQuote, $holding->fresh()->latest_realtime_price_id);
        $this->assertDatabaseCount('stock_realtime_prices', 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_reload_rejects_unauthenticated_requests_missing_stocks_and_concurrent_reloads(): void
    {
        $holding = $this->holding();
        $url = "/admin/watchlist/holdings/{$holding->id}/price/reload";
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($this->admin())->postJson('/admin/watchlist/holdings/999999/price/reload')->assertNotFound();
        $lock = Cache::lock('free-intraday.reload.'.$holding->id, 90);
        $lock->get();
        try {
            $this->postJson($url)->assertConflict();
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_a_non_admin_cannot_reload_a_stock(): void
    {
        $holding = $this->holding();
        $this->actingAs(User::factory()->create())
            ->postJson("/admin/watchlist/holdings/{$holding->id}/price/reload")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_source_error_without_a_stored_quote_is_reported_without_fabricating_a_price(): void
    {
        $holding = $this->holding();
        Http::fake(['*' => Http::response('', 503)]);
        $this->actingAs($this->admin())->postJson("/admin/watchlist/holdings/{$holding->id}/price/reload")
            ->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('holding.latest_price', null);
        $this->assertDatabaseCount('stock_realtime_prices', 0);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_reload_bypasses_the_free_yahoo_cache_for_only_the_selected_us_stock(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 21:00:00', 'UTC'));
        $holding = StockHolding::factory()->create(['symbol' => 'ARGT', 'isin' => 'US37950E2596', 'exchange' => 'US', 'currency' => 'USD']);
        $other = StockHolding::factory()->create(['symbol' => 'AAPL', 'isin' => 'US0378331005', 'exchange' => 'US', 'currency' => 'USD']);
        $this->fakeYahooMarketData(['ARGT' => ['price' => 79, 'at' => '2026-11-04 20:00:00'], 'AAPL' => ['price' => 250, 'at' => '2026-11-04 20:00:00']]);
        app(FreeIntradayMarketData::class)->syncAll();
        $oldOtherQuote = $other->fresh()->latest_realtime_price_id;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeYahooMarketData(['ARGT' => ['price' => 80, 'at' => '2026-11-04 20:05:00']]);
        $this->actingAs($this->admin())->postJson("/admin/watchlist/holdings/{$holding->id}/price/reload")
            ->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('holding.latest_price', '80.000000');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ARGT'));
        $this->assertSame($oldOtherQuote, $other->fresh()->latest_realtime_price_id);
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['name' => 'UNIQA', 'symbol' => 'UN9', 'isin' => 'AT0000821103', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
