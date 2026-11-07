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

    public function test_retired_research_settings_routes_return_not_found(): void
    {
        $admin = $this->adminUser();
        $settings = AnalyzeResearchSetting::factory()->for($admin)->create();

        $this->actingAs($admin)->getJson('/admin/analyze/research-settings')->assertNotFound();
        $this->patchJson('/admin/analyze/research-settings', ['rows' => 100])->assertNotFound();

        $this->assertSame($settings->settings, $settings->fresh()->settings);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
