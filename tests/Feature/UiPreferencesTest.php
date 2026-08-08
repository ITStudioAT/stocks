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
            ->assertJsonPath('ui_preferences.depot_price_source', 'latest')
            ->assertJsonPath('ui_preferences.analyze_trend_row_limit', 200)
            ->assertJsonPath('ui_preferences.analyze_trend_excluded_holding_ids', [])
            ->assertJsonPath('ui_preferences.analyze_trend_trade_amounts', [7000, 5000, 3000])
            ->assertJsonPath('ui_preferences.analyze_trend_max_invest_amount', 0)
            ->assertJsonPath('ui_preferences.analyze_trend_virtual_buy_amount', 7000)
            ->assertJsonPath('ui_preferences.analyze_trend_streak_buy_thresholds', [-4, -3, -2, -1, 0])
            ->assertJsonPath('ui_preferences.analyze_trend_streak_sell_threshold', 3);

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

    public function test_admin_can_store_user_specific_analyze_trend_preferences(): void
    {
        $admin = $this->adminUser();
        $otherAdmin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_row_limit' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('ui_preferences.analyze_trend_row_limit', 500);

        $config = AppConfig::query()->where('key', "ui.preferences.user.{$admin->id}")->firstOrFail();

        $this->assertSame(500, $config->value['analyze_trend_row_limit']);

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_excluded_holding_ids' => [11, 8, 8],
            ])
            ->assertOk()
            ->assertJsonPath('ui_preferences.analyze_trend_excluded_holding_ids', [11, 8]);

        $config->refresh();

        $this->assertSame([11, 8], $config->value['analyze_trend_excluded_holding_ids']);

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_trade_amounts' => [8000, 0, 0],
                'analyze_trend_max_invest_amount' => 25000,
                'analyze_trend_virtual_buy_amount' => 8000,
            ])
            ->assertOk()
            ->assertJsonPath('ui_preferences.analyze_trend_trade_amounts', [8000, 0, 0])
            ->assertJsonPath('ui_preferences.analyze_trend_max_invest_amount', 25000)
            ->assertJsonPath('ui_preferences.analyze_trend_virtual_buy_amount', 8000);

        $config->refresh();

        $this->assertSame([8000, 0, 0], $config->value['analyze_trend_trade_amounts']);
        $this->assertSame(25000, $config->value['analyze_trend_max_invest_amount']);
        $this->assertSame(8000, $config->value['analyze_trend_virtual_buy_amount']);

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_streak_buy_thresholds' => [null, -3.5, -2.5, -1.5, -1, 0],
                'analyze_trend_streak_sell_threshold' => 3.5,
            ])
            ->assertOk()
            ->assertJsonPath('ui_preferences.analyze_trend_streak_buy_thresholds', [null, -3.5, -2.5, -1.5, -1, 0])
            ->assertJsonPath('ui_preferences.analyze_trend_streak_sell_threshold', 3.5);

        $config->refresh();

        $this->assertSame([null, -3.5, -2.5, -1.5, -1, 0], $config->value['analyze_trend_streak_buy_thresholds']);
        $this->assertSame(3.5, $config->value['analyze_trend_streak_sell_threshold']);

        $this->actingAs($otherAdmin)
            ->getJson('/admin/ui-preferences')
            ->assertOk()
            ->assertJsonPath('ui_preferences.analyze_trend_row_limit', 200)
            ->assertJsonPath('ui_preferences.analyze_trend_excluded_holding_ids', [])
            ->assertJsonPath('ui_preferences.analyze_trend_trade_amounts', [7000, 5000, 3000])
            ->assertJsonPath('ui_preferences.analyze_trend_max_invest_amount', 0)
            ->assertJsonPath('ui_preferences.analyze_trend_virtual_buy_amount', 7000)
            ->assertJsonPath('ui_preferences.analyze_trend_streak_buy_thresholds', [-4, -3, -2, -1, 0])
            ->assertJsonPath('ui_preferences.analyze_trend_streak_sell_threshold', 3);
    }

    public function test_admin_must_provide_valid_analyze_trend_streak_rules(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_streak_buy_thresholds' => [],
                'analyze_trend_streak_sell_threshold' => 101,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'analyze_trend_streak_buy_thresholds',
                'analyze_trend_streak_sell_threshold',
            ]);

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_streak_buy_thresholds' => [-4, 1],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('analyze_trend_streak_buy_thresholds.1');
    }

    public function test_admin_must_provide_a_valid_virtual_buy_amount(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson('/admin/ui-preferences', [
                'analyze_trend_virtual_buy_amount' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('analyze_trend_virtual_buy_amount');
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
