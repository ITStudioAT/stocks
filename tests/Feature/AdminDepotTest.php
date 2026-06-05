<?php

namespace Tests\Feature;

use App\Models\Depot;
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
            ->assertJsonPath('depot.name', 'Active depot');
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
            ->assertOk();
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
