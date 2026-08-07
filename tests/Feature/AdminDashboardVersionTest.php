<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_current_and_installed_versions(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->getJson('/admin/dashboard/version')
            ->assertOk()
            ->assertJsonPath('current_version', config('stocks.version'))
            ->assertJsonStructure([
                'current_version',
                'versions' => [
                    '*' => ['key', 'label', 'version'],
                ],
            ]);

        $versions = collect($response->json('versions'))->keyBy('key');

        $this->assertSame(app()->version(), $versions->get('laravel')['version']);
        $this->assertSame(PHP_VERSION, $versions->get('php')['version']);
        $this->assertNotEmpty($versions->get('vue')['version']);
        $this->assertNotEmpty($versions->get('vuetify')['version']);
        $this->assertNotEmpty($versions->get('vite')['version']);
    }

    public function test_missing_application_version_uses_a_safe_fallback(): void
    {
        config(['stocks.version' => null]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/dashboard/version')
            ->assertOk()
            ->assertJsonPath('current_version', 'x.x.x');
    }

    public function test_guest_cannot_view_installed_versions(): void
    {
        $this->getJson('/admin/dashboard/version')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
