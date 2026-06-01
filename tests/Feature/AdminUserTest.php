<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users_for_selected_company_with_pagination(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
        ]);
        $otherCompany = Company::factory()->create();
        $admin = $this->superAdminUser([
            'company_id' => $company->id,
        ]);

        User::factory()
            ->count(12)
            ->create([
                'company_id' => $company->id,
            ])
            ->each(fn (User $user): User => $user->assignRole('admin'));

        User::factory()
            ->count(5)
            ->create([
                'company_id' => $otherCompany->id,
            ])
            ->each(fn (User $user): User => $user->assignRole('admin'));

        $this->actingAs($admin)
            ->getJson('/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(3, 'users')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('roles.0', 'admin')
            ->assertJsonPath('roles.1', 'super_admin')
            ->assertJsonStructure([
                'companies' => [
                    '*' => ['id', 'company_name_1'],
                ],
            ]);
    }

    public function test_admin_can_create_a_user(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'company_name_1' => 'ITStudio.at',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/users', [
                'last_name' => 'Demo',
                'first_name' => 'User',
                'email' => 'demo@example.com',
                'company_id' => $company->id,
                'roles' => ['admin'],
            ])
            ->assertCreated()
            ->assertJsonPath('user.name', 'Demo User')
            ->assertJsonPath('user.email', 'demo@example.com')
            ->assertJsonPath('user.company_id', $company->id)
            ->assertJsonPath('user.company_name', 'ITStudio.at')
            ->assertJsonPath('user.roles.0', 'admin');

        $user = User::where('email', 'demo@example.com')->firstOrFail();

        $this->assertNotNull($user->password);
        $this->assertFalse(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_admin_can_update_a_user(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create([
            'company_name_1' => 'Updated Company',
        ]);
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $user->assignRole('admin');

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$user->id}", [
                'last_name' => 'Updated',
                'first_name' => 'User',
                'email' => 'updated@example.com',
                'company_id' => $company->id,
                'roles' => ['super_admin'],
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated User')
            ->assertJsonPath('user.email', 'updated@example.com')
            ->assertJsonPath('user.company_id', $company->id)
            ->assertJsonPath('user.roles.0', 'super_admin');

        $user->refresh();

        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertTrue($user->company()->is($company));
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_admin_can_delete_another_user(): void
    {
        $admin = $this->superAdminUser();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($admin)
            ->deleteJson("/admin/users/{$user->id}")
            ->assertOk();

        $this->assertModelMissing($user);
    }

    public function test_admin_cannot_delete_their_own_user(): void
    {
        $admin = $this->superAdminUser();

        $this->actingAs($admin)
            ->deleteJson("/admin/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertModelExists($admin);
    }

    public function test_kron_guenther_cannot_be_deleted(): void
    {
        $admin = $this->superAdminUser();
        $user = User::factory()->create([
            'email' => 'kron@naturwelt.at',
            'last_name' => 'Kron',
            'first_name' => 'Günther',
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($admin)
            ->deleteJson("/admin/users/{$user->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertModelExists($user);
    }

    public function test_kron_guenther_super_admin_role_cannot_be_removed(): void
    {
        $admin = $this->superAdminUser();
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'email' => 'kron@naturwelt.at',
            'last_name' => 'Kron',
            'first_name' => 'Günther',
        ]);
        $user->syncRoles(['admin', 'super_admin']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$user->id}", [
                'last_name' => 'Kron',
                'first_name' => 'Günther',
                'email' => 'kron@naturwelt.at',
                'company_id' => $company->id,
                'roles' => ['admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    public function test_regular_admin_cannot_manage_users(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/users')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function superAdminUser(array $attributes = []): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create($attributes);
        $user->assignRole('super_admin');

        return $user;
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
