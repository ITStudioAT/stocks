<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UiPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_ui_preferences(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/ui-preferences')
            ->assertOk()
            ->assertJsonPath('ui_preferences.depot_price_source', 'latest');

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'depot_price_source' => 'flatex',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'UI preferences updated.')
            ->assertJsonPath('ui_preferences.depot_price_source', 'flatex');

        $config = AppConfig::query()->where('key', 'ui.preferences')->firstOrFail();

        $this->assertSame('flatex', $config->value['depot_price_source']);
    }

    public function test_admin_must_provide_a_valid_depot_price_source(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'depot_price_source' => 'other',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('depot_price_source');
    }

    public function test_guest_cannot_manage_ui_preferences(): void
    {
        $this->getJson('/admin/ui-preferences')->assertUnauthorized();
        $this->patchJson('/admin/ui-preferences', ['depot_price_source' => 'flatex'])->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
