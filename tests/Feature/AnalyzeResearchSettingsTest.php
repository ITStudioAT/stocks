<?php

namespace Tests\Feature;

use App\Models\AnalyzeResearchSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnalyzeResearchSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_default_research_settings(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/analyze/research-settings')
            ->assertOk()
            ->assertExactJson([
                'research_settings' => [
                    'rows' => 200,
                    'buy_rules' => [
                        ['enabled' => true, 'from' => -4, 'to' => -4],
                        ['enabled' => true, 'from' => -3, 'to' => -3],
                        ['enabled' => true, 'from' => -2, 'to' => -2],
                        ['enabled' => true, 'from' => -1, 'to' => -1],
                        ['enabled' => true, 'from' => 0, 'to' => 0],
                    ],
                    'buy_step' => 0.1,
                    'sell' => ['from' => 3, 'to' => 3, 'step' => 0.1],
                    'invest' => ['from' => 7000, 'to' => 7000, 'step' => 100],
                    'max_invest' => [
                        'enabled' => false,
                        'from' => 80000,
                        'to' => 80000,
                        'step' => 1000,
                    ],
                ],
            ]);

        $setting = AnalyzeResearchSetting::query()->whereBelongsTo($admin)->firstOrFail();

        $this->assertSame(0.1, $setting->settings['buy_step']);
        $this->assertSame(0.1, $setting->settings['sell']['step']);
    }

    public function test_admin_can_store_decimal_research_settings(): void
    {
        $admin = $this->adminUser();

        $payload = [
            'rows' => 350,
            'buy_rules' => [
                ['enabled' => true, 'from' => -5.75, 'to' => -3.25],
                ['enabled' => false, 'from' => -3.2, 'to' => -1.05],
            ],
            'buy_step' => 0.05,
            'sell' => ['from' => 2.15, 'to' => 6.75, 'step' => 0.25],
            'invest' => ['from' => 4500.25, 'to' => 8200.75, 'step' => 250.5],
            'max_invest' => [
                'enabled' => true,
                'from' => 60000.5,
                'to' => 90000.75,
                'step' => 5000.25,
            ],
        ];

        $this->actingAs($admin)
            ->patchJson('/admin/analyze/research-settings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Research settings saved.')
            ->assertJsonPath('research_settings', $payload);

        $setting = AnalyzeResearchSetting::query()->whereBelongsTo($admin)->firstOrFail();

        $this->assertSame($payload, $setting->settings);
    }

    public function test_research_settings_are_user_specific(): void
    {
        $admin = $this->adminUser();
        $otherAdmin = $this->adminUser();

        AnalyzeResearchSetting::factory()->for($admin)->create([
            'settings' => [
                'rows' => 500,
                'buy_rules' => [['enabled' => false, 'from' => -8.5, 'to' => -6.5]],
                'buy_step' => 0.25,
                'sell' => ['from' => 4.25, 'to' => 7.25, 'step' => 0.5],
                'invest' => ['from' => 5000.0, 'to' => 7500.0, 'step' => 250.0],
                'max_invest' => ['enabled' => false, 'from' => 80000.0, 'to' => 80000.0, 'step' => 1000.0],
            ],
        ]);

        $this->actingAs($otherAdmin)
            ->getJson('/admin/analyze/research-settings')
            ->assertOk()
            ->assertJsonPath('research_settings.buy_step', 0.1)
            ->assertJsonPath('research_settings.sell.step', 0.1)
            ->assertJsonCount(5, 'research_settings.buy_rules');
    }

    public function test_legacy_shared_step_is_split_between_buy_and_sell(): void
    {
        $admin = $this->adminUser();

        $setting = AnalyzeResearchSetting::factory()->for($admin)->create([
            'settings' => [
                'rows' => 200,
                'buy_rules' => [['enabled' => true, 'from' => -4.0, 'to' => -2.0]],
                'sell' => ['from' => 2.0, 'to' => 5.0],
                'step' => 0.35,
                'invest' => ['from' => 7000.0, 'to' => 7000.0, 'step' => 100.0],
                'max_invest' => ['enabled' => false, 'from' => 80000.0, 'to' => 80000.0, 'step' => 1000.0],
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/analyze/research-settings')
            ->assertOk()
            ->assertJsonPath('research_settings.buy_step', 0.35)
            ->assertJsonPath('research_settings.sell.step', 0.35);

        $setting->refresh();

        $this->assertArrayNotHasKey('step', $setting->settings);
        $this->assertSame(0.35, $setting->settings['buy_step']);
        $this->assertSame(0.35, $setting->settings['sell']['step']);
    }

    public function test_admin_must_provide_valid_research_intervals(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->patchJson('/admin/analyze/research-settings', [
                'rows' => 0,
                'buy_rules' => [
                    ['enabled' => true, 'from' => -2.5, 'to' => -5.5],
                ],
                'buy_step' => 0,
                'sell' => ['from' => 8.5, 'to' => 3.5, 'step' => 0],
                'invest' => ['from' => 9000.0, 'to' => 5000.0, 'step' => 0],
                'max_invest' => ['enabled' => true, 'from' => 90000.0, 'to' => 50000.0, 'step' => 0],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'buy_rules.0.from',
                'sell.from',
                'buy_step',
                'sell.step',
                'rows',
                'invest.from',
                'invest.step',
                'max_invest.from',
                'max_invest.step',
            ]);

        $this->actingAs($admin)
            ->patchJson('/admin/analyze/research-settings', [
                'rows' => 200,
                'buy_rules' => ['invalid'],
                'buy_step' => 0.1,
                'sell' => ['from' => 3.0, 'to' => 4.0, 'step' => 0.1],
                'invest' => ['from' => 7000.0, 'to' => 7000.0, 'step' => 100.0],
                'max_invest' => ['enabled' => false, 'from' => 80000.0, 'to' => 80000.0, 'step' => 1000.0],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buy_rules.0');
    }

    public function test_guest_cannot_manage_research_settings(): void
    {
        $this->getJson('/admin/analyze/research-settings')->assertUnauthorized();
        $this->patchJson('/admin/analyze/research-settings', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/admin/analyze/research-settings')
            ->assertForbidden();

        $this->patchJson('/admin/analyze/research-settings', [])
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
