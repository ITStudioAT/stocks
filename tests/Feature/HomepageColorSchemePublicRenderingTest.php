<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\HomepageColorScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomepageColorSchemePublicRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_public_homepage_inlines_only_the_active_pre_rendered_css_variables(): void
    {
        $client = Client::factory()->create([
            'signature' => 'scheme-client',
            'is_published' => true,
        ]);

        HomepageColorScheme::factory()->create([
            'homepage_id' => $client->id,
            'css_variables' => ":root {\n  --color-page-bg: #ABCDEF;\n  --color-text: #111111;\n}\n",
            'is_active' => false,
        ]);

        HomepageColorScheme::factory()->create([
            'homepage_id' => $client->id,
            'css_variables' => ":root {\n  --color-page-bg: #123456;\n  --color-text: #FFFFFF;\n}\n",
            'is_active' => true,
        ]);

        $this->get('/'.$client->signature)
            ->assertOk()
            ->assertSee('--color-page-bg: #123456', false)
            ->assertDontSee('--color-page-bg: #ABCDEF', false)
            ->assertSee('background: var(--color-page-bg)', false)
            ->assertSee('color: var(--color-text)', false)
            ->assertDontSee('background: #123456', false);
    }

    public function test_public_homepage_uses_default_semantic_variables_when_no_scheme_exists(): void
    {
        $client = Client::factory()->create([
            'signature' => 'fallback-client',
            'is_published' => true,
        ]);

        $this->get('/'.$client->signature)
            ->assertOk()
            ->assertSee('--color-page-bg:', false)
            ->assertSee('var(--color-heading)', false)
            ->assertDontSee('--source-', false)
            ->assertDontSee('--role-', false);
    }

    public function test_admin_can_generate_and_store_an_active_homepage_color_scheme(): void
    {
        $admin = $this->superAdminUser();
        $client = Client::factory()->create();

        HomepageColorScheme::factory()->create([
            'homepage_id' => $client->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/clients/{$client->id}/color-schemes", [
                'scheme_name' => 'Brand launch',
                'source_colors' => [
                    [
                        'hex' => '#07f',
                        'must_use' => true,
                        'origin' => 'analyzed_website',
                        'source_url' => 'https://example.com',
                        'user_locked' => true,
                    ],
                    [
                        'hex' => '#DC3545',
                        'must_use' => true,
                        'origin' => 'brand_guide',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('color_scheme.scheme_name', 'Brand launch')
            ->assertJsonPath('color_scheme.source_colors.source_color_1.hex', '#0077FF')
            ->assertJsonPath('color_scheme.role_colors.role_decorative.hex', '#DC3545')
            ->assertJsonPath('color_scheme.accessibility_mode', 'standard');

        $this->assertDatabaseHas('homepage_color_schemes', [
            'homepage_id' => $client->id,
            'scheme_name' => 'Brand launch',
            'is_active' => true,
        ]);
        $storedScheme = HomepageColorScheme::query()
            ->where('homepage_id', $client->id)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertSame(1, HomepageColorScheme::query()
            ->where('homepage_id', $client->id)
            ->where('is_active', true)
            ->count());
        $this->assertNotNull($storedScheme->harmony_score);
        $this->assertIsArray($storedScheme->warnings_json);
        $this->assertSame('standard', $storedScheme->accessibility_mode);
    }

    public function test_regular_admin_cannot_generate_scheme_for_another_company_homepage(): void
    {
        Role::findOrCreate('admin');

        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create([
            'company_id' => $company->id,
        ]);
        $admin->assignRole('admin');
        $client = Client::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/clients/{$client->id}/color-schemes", [
                'source_colors' => [
                    ['hex' => '#007BFF', 'must_use' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_admin_color_scheme_request_rejects_invalid_hex_values(): void
    {
        $admin = $this->superAdminUser();
        $client = Client::factory()->create();

        $this->actingAs($admin)
            ->postJson("/admin/clients/{$client->id}/color-schemes", [
                'source_colors' => [
                    ['hex' => 'not-a-color', 'must_use' => true],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_colors.0.hex');
    }

    private function superAdminUser(): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
