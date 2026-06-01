<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_companies_with_pagination(): void
    {
        $admin = $this->superAdminUser();

        Company::factory()->count(12)->create();

        $this->actingAs($admin)
            ->getJson('/admin/companies?page=2')
            ->assertOk()
            ->assertJsonCount(4, 'companies')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 14);
    }

    public function test_admin_can_create_a_company(): void
    {
        $admin = $this->superAdminUser();

        $this->actingAs($admin)
            ->postJson('/admin/companies', $this->companyPayload([
                'company_name_1' => 'Naturwelt GmbH',
            ]))
            ->assertCreated()
            ->assertJsonPath('company.company_name_1', 'Naturwelt GmbH')
            ->assertJsonPath('company.city', 'Wien');

        $this->assertDatabaseHas('companies', [
            'company_name_1' => 'Naturwelt GmbH',
            'postal_code' => '1010',
            'city' => 'Wien',
        ]);
    }

    public function test_admin_can_update_a_company(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'company_name_1' => 'Old Company',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/companies/{$company->id}", $this->companyPayload([
                'company_name_1' => 'Updated Company',
                'company_name_2' => 'Holding',
            ]))
            ->assertOk()
            ->assertJsonPath('company.company_name_1', 'Updated Company')
            ->assertJsonPath('company.company_name_2', 'Holding');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'company_name_1' => 'Updated Company',
            'company_name_2' => 'Holding',
        ]);
    }

    public function test_admin_can_activate_a_company(): void
    {
        $admin = $this->superAdminUser();
        $activeCompany = Company::factory()->create([
            'is_active' => true,
        ]);
        $company = Company::factory()->create([
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/companies/{$company->id}/activate")
            ->assertOk()
            ->assertJsonPath('company.is_active', true);

        $this->assertTrue($company->fresh()->is_active);
        $this->assertFalse($activeCompany->fresh()->is_active);
    }

    public function test_admin_can_delete_a_company(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/companies/{$company->id}")
            ->assertOk();

        $this->assertModelMissing($company);
    }

    public function test_admin_cannot_delete_a_company_with_users(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        User::factory()->create([
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/companies/{$company->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company');

        $this->assertModelExists($company);
    }

    public function test_admin_cannot_delete_a_company_with_clients(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        Client::factory()->create([
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/companies/{$company->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company');

        $this->assertModelExists($company);
    }

    public function test_initial_company_and_kron_user_are_seeded(): void
    {
        $this->seed();

        $company = Company::where('company_name_1', 'ITStudio.at')->firstOrFail();
        $user = User::where('email', 'kron@naturwelt.at')->firstOrFail();
        $client = Client::where('signature', 'naturwelt')->firstOrFail();

        $this->assertSame('by Dipl.-Ing. Gütnher Kron', $company->company_name_2);
        $this->assertSame('Salzburger Straße 87b', $company->street);
        $this->assertSame('5110', $company->postal_code);
        $this->assertSame('Oberndorf', $company->city);
        $this->assertSame('Österreich', $company->country);
        $this->assertTrue($user->company()->is($company));
        $this->assertTrue($client->company()->is($company));
    }

    public function test_guest_cannot_manage_companies(): void
    {
        $company = Company::factory()->create();

        $this->getJson('/admin/companies')->assertUnauthorized();
        $this->postJson('/admin/companies', $this->companyPayload())->assertUnauthorized();
        $this->patchJson("/admin/companies/{$company->id}", $this->companyPayload())->assertUnauthorized();
        $this->deleteJson("/admin/companies/{$company->id}")->assertUnauthorized();
    }

    public function test_regular_admin_cannot_manage_companies(): void
    {
        $admin = $this->adminUser();
        $company = Company::factory()->create();

        $this->actingAs($admin)->getJson('/admin/companies')->assertForbidden();
        $this->actingAs($admin)->postJson('/admin/companies', $this->companyPayload())->assertForbidden();
        $this->actingAs($admin)->patchJson("/admin/companies/{$company->id}", $this->companyPayload())->assertForbidden();
        $this->actingAs($admin)->patchJson("/admin/companies/{$company->id}/activate")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/admin/companies/{$company->id}")->assertForbidden();
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array{company_name_1: string, company_name_2: ?string, street: string, postal_code: string, city: string, country: string}
     */
    private function companyPayload(array $overrides = []): array
    {
        return [
            'company_name_1' => 'Demo Company',
            'company_name_2' => null,
            'street' => 'Demo Strasse 1',
            'postal_code' => '1010',
            'city' => 'Wien',
            'country' => 'Austria',
            ...$overrides,
        ];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function superAdminUser(): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
