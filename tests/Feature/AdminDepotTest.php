<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDepotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_depots_with_pagination(): void
    {
        $admin = $this->adminUser();

        Depot::factory()->count(12)->create();

        $this->actingAs($admin)
            ->getJson('/admin/depots?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'depots')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonStructure([
                'depots' => [
                    [
                        'id',
                        'name',
                        'account_balance',
                        'current_account_balance',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);
    }

    public function test_admin_can_list_depots_with_current_account_balance(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '100.00',
        ]);
        $holding = StockHolding::factory()->create([
            'latest_price' => '12.500000',
        ]);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '3.00000000',
            'total_amount' => '30.00',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'sell',
            'pieces' => '1.00000000',
            'total_amount' => '10.00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depots')
            ->assertOk()
            ->assertJsonPath('depots.0.account_balance', '100.00')
            ->assertJsonPath('depots.0.current_account_balance', '125.00');
    }

    public function test_admin_can_list_depots_with_current_account_balance_from_latest_stock_price(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '44286.56',
        ]);
        $latestPrice = StockPrice::factory()->create([
            'price' => '42.60000000',
        ]);
        $holding = StockHolding::factory()->create([
            'latest_price' => null,
            'latest_stock_price_id' => $latestPrice->id,
        ]);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '300.00000000',
            'total_amount' => '12780.00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depots')
            ->assertOk()
            ->assertJsonPath('depots.0.account_balance', '44286.56')
            ->assertJsonPath('depots.0.current_account_balance', '57066.56');
    }

    public function test_admin_can_create_a_depot(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/depots', [
                'name' => 'Long term depot',
                'account_balance' => '12345.67',
            ])
            ->assertCreated()
            ->assertJsonPath('depot.name', 'Long term depot')
            ->assertJsonPath('depot.account_balance', '12345.67')
            ->assertJsonPath('depot.is_active', true);

        $this->assertDatabaseHas('depots', [
            'name' => 'Long term depot',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_a_depot(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'name' => 'Old depot',
            'account_balance' => '100.00',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/depots/{$depot->id}", [
                'name' => 'Updated depot',
                'account_balance' => '250.50',
            ])
            ->assertOk()
            ->assertJsonPath('depot.name', 'Updated depot')
            ->assertJsonPath('depot.account_balance', '250.50')
            ->assertJsonPath('depot.is_active', true);

        $this->assertDatabaseHas('depots', [
            'id' => $depot->id,
            'name' => 'Updated depot',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_activate_one_depot(): void
    {
        $admin = $this->adminUser();
        $activeDepot = Depot::factory()->create([
            'is_active' => true,
        ]);
        $inactiveDepot = Depot::factory()->create([
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/depots/{$inactiveDepot->id}/activate")
            ->assertOk()
            ->assertJsonPath('depot.id', $inactiveDepot->id)
            ->assertJsonPath('depot.is_active', true);

        $this->assertFalse($activeDepot->fresh()->is_active);
        $this->assertTrue($inactiveDepot->fresh()->is_active);
    }

    public function test_admin_can_fetch_the_active_depot(): void
    {
        $admin = $this->adminUser();
        Depot::factory()->create([
            'name' => 'Inactive depot',
            'is_active' => false,
        ]);
        Depot::factory()->create([
            'name' => 'Active depot',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depots/active')
            ->assertOk()
            ->assertJsonPath('depot.name', 'Active depot')
            ->assertJsonPath('app_version', config('stocks.version'));
    }

    public function test_guest_cannot_list_depots(): void
    {
        $this->getJson('/admin/depots')->assertUnauthorized();
    }

    public function test_admin_can_open_depots_menu_page(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get('/admin/menu/depots')
            ->assertOk()
            ->assertSee('<title>GKStocks</title>', false);
    }

    public function test_admin_can_open_nested_analyze_menu_page(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get('/admin/menu/analyze/detail')
            ->assertOk();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
