<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_route_renders_the_homepage_view(): void
    {
        $response = $this->get(route('homepage'));

        $response->assertStatus(200);
        $response->assertViewIs('homepage');
        $response->assertSee('id="homepage"', false);
        $response->assertSee('<title>GKStocks</title>', false);
    }

    public function test_homepage_loads_the_editorial_google_fonts(): void
    {
        $response = $this->get(route('homepage'));

        $response->assertStatus(200);
        $response->assertSee('Newsreader', false);
        $response->assertSee('IBM+Plex+Mono', false);
    }

    public function test_public_indices_endpoint_returns_index_data_without_authentication(): void
    {
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'name' => 'Austrian Traded Index in EUR',
            'country' => 'Austria',
            'currency' => 'EUR',
            'latest_price' => '6116.52980000',
            'latest_price_change_pct' => '0.333979',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'DAX',
            'name' => 'DAX Index',
            'country' => 'Germany',
            'currency' => 'EUR',
            'latest_price' => null,
            'latest_price_change_pct' => null,
        ]);

        $this->getJson('/indices')
            ->assertOk()
            ->assertJsonCount(2, 'indexes')
            ->assertJsonPath('indexes.0.symbol', 'ATX')
            ->assertJsonPath('indexes.0.country', 'Austria')
            ->assertJsonPath('indexes.0.currency', 'EUR')
            ->assertJsonPath('indexes.0.latest_price', '6116.5298')
            ->assertJsonPath('indexes.0.latest_price_change_pct', '0.33')
            ->assertJsonPath('indexes.1.symbol', 'DAX')
            ->assertJsonPath('indexes.1.latest_price', null)
            ->assertJsonPath('indexes.1.latest_price_change_pct', null);
    }

    public function test_public_indices_endpoint_returns_empty_array_when_no_indices_configured(): void
    {
        $this->getJson('/indices')
            ->assertOk()
            ->assertJsonCount(0, 'indexes');
    }

    public function test_depot_sum_sign_returns_zero_when_no_active_depot(): void
    {
        $this->getJson('/depot-sum-sign')
            ->assertOk()
            ->assertJsonPath('sign', 0);
    }

    public function test_depot_sum_sign_returns_zero_when_depot_has_no_positions(): void
    {
        Depot::factory()->create(['is_active' => true]);

        $this->getJson('/depot-sum-sign')
            ->assertOk()
            ->assertJsonPath('sign', 0);
    }

    public function test_depot_sum_sign_returns_negative_one_when_sum_is_negative(): void
    {
        $depot = Depot::factory()->create(['is_active' => true]);
        $holding = StockHolding::factory()->create(['latest_price' => '90.000000']);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '10.00000000',
            'total_amount' => '1000.00',
            'booked_at' => now(),
        ]);

        $this->getJson('/depot-sum-sign')
            ->assertOk()
            ->assertJsonPath('sign', -1);
    }

    public function test_depot_sum_sign_returns_one_when_sum_is_positive(): void
    {
        $depot = Depot::factory()->create(['is_active' => true]);
        $holding = StockHolding::factory()->create(['latest_price' => '110.000000']);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '10.00000000',
            'total_amount' => '1000.00',
            'booked_at' => now(),
        ]);

        $this->getJson('/depot-sum-sign')
            ->assertOk()
            ->assertJsonPath('sign', 1);
    }
}
