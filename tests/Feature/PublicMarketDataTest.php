<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_public_routes_do_not_create_anonymous_sessions_or_cookies(): void
    {
        foreach (['/', '/indices', '/depot-sum-sign'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertCookieMissing(config('session.cookie'))
                ->assertCookieMissing('XSRF-TOKEN');
        }

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_market_sign_ignores_private_portfolio_data(): void
    {
        IndexWatchItem::factory()->create(['latest_price_change_pct' => '1.000000']);

        $depot = Depot::factory()->create(['is_active' => true]);
        $holding = StockHolding::factory()->create(['latest_price' => '1.000000']);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '100.00000000',
            'total_amount' => '1000000.00',
            'booked_at' => now(),
        ]);

        $this->getJson('/depot-sum-sign')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, stale-while-revalidate=300')
            ->assertJsonPath('sign', 1);
    }

    public function test_public_market_responses_are_cached(): void
    {
        $index = IndexWatchItem::factory()->create(['latest_price_change_pct' => '1.000000']);

        $this->getJson('/depot-sum-sign')->assertJsonPath('sign', 1);

        $index->update(['latest_price_change_pct' => '-1.000000']);

        $this->getJson('/depot-sum-sign')->assertJsonPath('sign', 1);
    }
}
