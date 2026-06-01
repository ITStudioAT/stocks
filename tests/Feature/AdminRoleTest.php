<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_roles_with_pagination(): void
    {
        $admin = $this->superAdminUser();

        collect(range(1, 12))
            ->each(fn (int $index): Role => Role::findOrCreate("role-{$index}"));

        $this->actingAs($admin)
            ->getJson('/admin/roles?page=2')
            ->assertOk()
            ->assertJsonCount(4, 'roles')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 14);
    }

    public function test_admin_can_create_a_role(): void
    {
        $admin = $this->superAdminUser();

        $this->actingAs($admin)
            ->postJson('/admin/roles', [
                'name' => 'editor',
            ])
            ->assertCreated()
            ->assertJsonPath('role.name', 'editor')
            ->assertJsonPath('role.guard_name', 'web');

        $this->assertDatabaseHas('roles', [
            'name' => 'editor',
            'guard_name' => 'web',
        ]);
    }

    public function test_admin_can_update_a_role(): void
    {
        $admin = $this->superAdminUser();
        $role = Role::findOrCreate('editor');

        $this->actingAs($admin)
            ->patchJson("/admin/roles/{$role->id}", [
                'name' => 'publisher',
            ])
            ->assertOk()
            ->assertJsonPath('role.name', 'publisher');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'publisher',
        ]);
    }

    public function test_admin_can_delete_an_unused_role(): void
    {
        $admin = $this->superAdminUser();
        $role = Role::findOrCreate('editor');

        $this->actingAs($admin)
            ->deleteJson("/admin/roles/{$role->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', [
            'id' => $role->id,
        ]);
    }

    public function test_admin_cannot_delete_role_assigned_to_users(): void
    {
        $admin = $this->superAdminUser();
        $role = Role::findOrCreate('editor');
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($admin)
            ->deleteJson("/admin/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
        ]);
    }

    public function test_admin_cannot_edit_or_delete_system_roles(): void
    {
        $admin = $this->superAdminUser();
        $role = Role::findOrCreate('super_admin');

        $this->actingAs($admin)
            ->patchJson("/admin/roles/{$role->id}", [
                'name' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($admin)
            ->deleteJson("/admin/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'super_admin',
        ]);
    }

    public function test_regular_admin_cannot_manage_roles(): void
    {
        $admin = $this->adminUser();
        $role = Role::findOrCreate('editor');

        $this->actingAs($admin)->getJson('/admin/roles')->assertForbidden();
        $this->actingAs($admin)->postJson('/admin/roles', ['name' => 'publisher'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/admin/roles/{$role->id}", ['name' => 'publisher'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/admin/roles/{$role->id}")->assertForbidden();
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
        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
