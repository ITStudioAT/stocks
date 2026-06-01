<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_shows_homepagemaker_marketing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('homepage')
            ->assertSee('<div id="homepage"></div>', false);
    }

    public function test_published_client_homepage_is_available_by_signature(): void
    {
        $client = Client::factory()->create([
            'signature' => 'client-signature',
            'headline' => 'Client specific homepage',
            'is_published' => true,
        ]);

        $this->get('/'.$client->signature)
            ->assertOk()
            ->assertSee('Client specific homepage');
    }

    public function test_unpublished_client_homepage_returns_not_found(): void
    {
        $client = Client::factory()->unpublished()->create([
            'signature' => 'draft-client',
        ]);

        $this->get('/'.$client->signature)->assertNotFound();
    }

    public function test_admin_can_list_client_homepages(): void
    {
        Role::findOrCreate('admin');

        $company = Company::factory()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);
        $user->assignRole('admin');

        $client = Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Managed Client',
            'signature' => 'managed-client',
        ]);

        WebsiteAnalysis::factory()->count(2)->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)
            ->getJson('/admin/clients')
            ->assertOk()
            ->assertJsonPath('clients.0.name', 'Managed Client')
            ->assertJsonPath('clients.0.signature', 'managed-client')
            ->assertJsonPath('clients.0.analyses_count', 2)
            ->assertJsonPath('clients.0.company_id', $company->id);
    }

    public function test_regular_admin_only_lists_clients_from_their_company(): void
    {
        Role::findOrCreate('admin');

        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);
        $user->assignRole('admin');

        Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Visible Client',
        ]);
        Client::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Hidden Client',
        ]);

        $this->actingAs($user)
            ->getJson('/admin/clients')
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.name', 'Visible Client');
    }

    public function test_super_admin_can_filter_clients_by_company(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Selected Company Client',
        ]);
        Client::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Company Client',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/clients?company_id={$company->id}")
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.name', 'Selected Company Client');
    }

    public function test_clients_overview_page_is_only_reachable_as_super_admin(): void
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/menu/clients-overview')
            ->assertForbidden();

        $this->actingAs($this->superAdminUser())
            ->get('/admin/menu/clients-overview')
            ->assertOk();
    }

    public function test_roles_page_is_only_reachable_as_super_admin(): void
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/menu/roles')
            ->assertForbidden();

        $this->actingAs($this->superAdminUser())
            ->get('/admin/menu/roles')
            ->assertOk();
    }

    public function test_filtering_clients_by_company_does_not_move_clients(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Original Company Client',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/clients?company_id={$otherCompany->id}")
            ->assertOk()
            ->assertJsonCount(0, 'clients');

        $this->assertTrue($client->fresh()->company()->is($company));
    }

    public function test_super_admin_can_create_a_client_for_a_company(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();

        $this->actingAs($admin)
            ->postJson('/admin/clients', [
                'company_id' => $company->id,
                'name' => 'Created Client',
                'signature' => 'created-client',
            ])
            ->assertOk()
            ->assertJsonPath('client.company_id', $company->id)
            ->assertJsonPath('client.name', 'Created Client')
            ->assertJsonPath('client.signature', 'created-client')
            ->assertJsonPath('client.is_active', true);

        $this->assertDatabaseHas('clients', [
            'company_id' => $company->id,
            'name' => 'Created Client',
            'signature' => 'created-client',
            'headline' => 'Created Client',
            'is_published' => true,
            'is_active' => true,
        ]);
    }

    public function test_client_name_must_be_unique_for_the_company_when_creating(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();

        Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Shared Client',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/clients', [
                'company_id' => $company->id,
                'name' => 'Shared Client',
                'signature' => 'shared-client-copy',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_client_name_can_be_reused_in_another_company(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Shared Client',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/clients', [
                'company_id' => $otherCompany->id,
                'name' => 'Shared Client',
                'signature' => 'shared-client-other-company',
            ])
            ->assertOk()
            ->assertJsonPath('client.company_id', $otherCompany->id)
            ->assertJsonPath('client.name', 'Shared Client');
    }

    public function test_new_client_is_not_active_when_company_already_has_clients(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/clients', [
                'company_id' => $company->id,
                'name' => 'Second Client',
                'signature' => 'second-client',
            ])
            ->assertOk()
            ->assertJsonPath('client.is_active', false);

        $this->assertDatabaseHas('clients', [
            'company_id' => $company->id,
            'signature' => 'second-client',
            'is_active' => false,
        ]);
    }

    public function test_super_admin_can_update_a_client(): void
    {
        $admin = $this->superAdminUser();
        $client = Client::factory()->create([
            'name' => 'Old Client',
            'signature' => 'old-client',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/clients/{$client->id}", [
                'name' => 'Updated Client',
                'signature' => 'updated-client',
            ])
            ->assertOk()
            ->assertJsonPath('client.name', 'Updated Client')
            ->assertJsonPath('client.signature', 'updated-client');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Updated Client',
            'signature' => 'updated-client',
            'headline' => 'Updated Client',
        ]);
    }

    public function test_client_name_must_be_unique_for_the_company_when_updating(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Existing Client',
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'name' => 'Editable Client',
            'signature' => 'editable-client',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/clients/{$client->id}", [
                'name' => 'Existing Client',
                'signature' => 'editable-client',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame('Editable Client', $client->fresh()->name);
    }

    public function test_admin_can_activate_a_client_for_their_company(): void
    {
        Role::findOrCreate('admin');

        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'company_id' => $company->id,
        ]);
        $admin->assignRole('admin');

        $activeClient = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/clients/{$client->id}/activate")
            ->assertOk()
            ->assertJsonPath('client.is_active', true);

        $this->assertTrue($client->fresh()->is_active);
        $this->assertFalse($activeClient->fresh()->is_active);
    }

    public function test_regular_admin_cannot_activate_a_client_from_another_company(): void
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
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/clients/{$client->id}/activate")
            ->assertForbidden();

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_regular_admin_cannot_create_a_client(): void
    {
        Role::findOrCreate('admin');

        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'company_id' => $company->id,
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->postJson('/admin/clients', [
                'company_id' => $company->id,
                'name' => 'Blocked Client',
                'signature' => 'blocked-client',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('clients', [
            'signature' => 'blocked-client',
        ]);
    }

    public function test_super_admin_can_delete_a_client_with_confirmation(): void
    {
        Storage::fake('local');

        $admin = $this->superAdminUser();
        $client = Client::factory()->create();
        $analysis = WebsiteAnalysis::factory()->create([
            'company_id' => $client->company_id,
            'client_id' => $client->id,
            'result_path' => 'analyses/1/analysis.json',
        ]);

        Storage::disk('local')->put($analysis->result_path, '{}');

        $this->actingAs($admin)
            ->deleteJson("/admin/clients/{$client->id}", [
                'confirmation' => 'DELETE',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Client deleted.');

        $this->assertModelMissing($client);
        $this->assertModelMissing($analysis);
        Storage::disk('local')->assertMissing($analysis->result_path);
    }

    public function test_client_delete_requires_confirmation(): void
    {
        $admin = $this->superAdminUser();
        $client = Client::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/admin/clients/{$client->id}", [
                'confirmation' => 'delete',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertModelExists($client);
    }

    public function test_regular_admin_cannot_delete_a_client(): void
    {
        Role::findOrCreate('admin');

        $client = Client::factory()->create();
        $admin = User::factory()->create([
            'company_id' => $client->company_id,
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->deleteJson("/admin/clients/{$client->id}", [
                'confirmation' => 'DELETE',
            ])
            ->assertForbidden();

        $this->assertModelExists($client);
    }

    public function test_super_admin_can_search_companies_after_three_characters(): void
    {
        $admin = $this->superAdminUser();
        Company::factory()->create([
            'company_name_1' => 'Acme Studio',
        ]);
        Company::factory()->create([
            'company_name_1' => 'Other Company',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/companies/search?search=Acm')
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.company_name_1', 'Acme Studio');

        $this->actingAs($admin)
            ->getJson('/admin/companies/search?search=Ac')
            ->assertUnprocessable();
    }

    private function superAdminUser(): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
