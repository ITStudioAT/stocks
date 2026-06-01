<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_their_name(): void
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'last_name' => 'Old',
            'first_name' => 'Name',
        ]);
        $user->assignRole('admin');

        $this->actingAs($user)
            ->patchJson('/admin/profile/name', [
                'last_name' => 'New',
                'first_name' => 'Name',
            ])
            ->assertOk()
            ->assertJsonPath('user.last_name', 'New')
            ->assertJsonPath('user.first_name', 'Name')
            ->assertJsonPath('user.name', 'New Name');

        $this->assertSame('New', $user->fresh()->last_name);
        $this->assertSame('Name', $user->fresh()->first_name);
    }

    public function test_admin_can_update_their_password(): void
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $user->assignRole('admin');

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'password' => 'new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_password_update_does_not_require_the_current_password(): void
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $user->assignRole('admin');

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'password' => 'new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }
}
