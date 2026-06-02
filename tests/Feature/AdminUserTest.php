<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users_with_pagination(): void
    {
        $admin = $this->superAdminUser();

        User::factory()
            ->count(12)
            ->create()
            ->each(fn (User $user): User => $user->assignRole('admin'));

        $this->actingAs($admin)
            ->getJson('/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(3, 'users')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('roles.0', 'admin')
            ->assertJsonPath('roles.1', 'super_admin')
            ->assertJsonMissingPath('companies');
    }

    public function test_admin_can_create_a_user(): void
    {
        $admin = $this->superAdminUser();

        $this->actingAs($admin)
            ->postJson('/admin/users', [
                'last_name' => 'Demo',
                'first_name' => 'User',
                'email' => 'demo@example.com',
                'roles' => ['admin'],
            ])
            ->assertCreated()
            ->assertJsonPath('user.name', 'Demo User')
            ->assertJsonPath('user.email', 'demo@example.com')
            ->assertJsonPath('user.roles.0', 'admin')
            ->assertJsonMissingPath('user.company_id');

        $user = User::where('email', 'demo@example.com')->firstOrFail();

        $this->assertNotNull($user->password);
        $this->assertFalse(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_admin_can_update_a_user(): void
    {
        $admin = $this->superAdminUser();
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
                'roles' => ['super_admin'],
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated User')
            ->assertJsonPath('user.email', 'updated@example.com')
            ->assertJsonPath('user.roles.0', 'super_admin');

        $user->refresh();

        $this->assertTrue(Hash::check('old-password', $user->password));
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
            'first_name' => 'Guenther',
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
        $user = User::factory()->create([
            'email' => 'kron@naturwelt.at',
            'last_name' => 'Kron',
            'first_name' => 'Guenther',
        ]);
        $user->syncRoles(['admin', 'super_admin']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$user->id}", [
                'last_name' => 'Kron',
                'first_name' => 'Guenther',
                'email' => 'kron@naturwelt.at',
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
