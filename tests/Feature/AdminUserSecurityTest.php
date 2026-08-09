<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_status_survives_email_changes_and_prevents_a_second_request_bypass(): void
    {
        $actor = $this->superAdmin();
        $protectedUser = User::factory()->protectedAdministrator()->create();
        $protectedUser->assignRole('super_admin');

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$protectedUser->id}", [
                'last_name' => $protectedUser->last_name,
                'first_name' => $protectedUser->first_name,
                'email' => 'changed-protected@example.com',
                'roles' => ['super_admin'],
            ])
            ->assertOk()
            ->assertJsonPath('user.roles_locked', true)
            ->assertJsonPath('user.can_delete', false);

        $protectedUser->refresh();
        $this->assertTrue($protectedUser->is_protected);
        $this->assertSame('changed-protected@example.com', $protectedUser->email);

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$protectedUser->id}", [
                'last_name' => $protectedUser->last_name,
                'first_name' => $protectedUser->first_name,
                'email' => $protectedUser->email,
                'roles' => ['admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->actingAs($actor)
            ->deleteJson("/admin/users/{$protectedUser->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertModelExists($protectedUser);
        $this->assertTrue($protectedUser->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_cannot_demote_their_own_account(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$actor->id}", [
                'last_name' => $actor->last_name,
                'first_name' => $actor->first_name,
                'email' => $actor->email,
                'roles' => ['admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_clients_cannot_mark_new_users_as_protected(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->postJson('/admin/users', [
                'last_name' => 'Ordinary',
                'first_name' => 'Admin',
                'email' => 'ordinary@example.com',
                'roles' => ['admin'],
                'is_protected' => true,
            ])
            ->assertCreated();

        $this->assertFalse(User::query()->where('email', 'ordinary@example.com')->firstOrFail()->is_protected);
    }

    public function test_privileged_user_changes_are_written_to_the_security_audit_log(): void
    {
        Log::spy();
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->postJson('/admin/users', [
                'last_name' => 'Audited',
                'first_name' => 'Admin',
                'email' => 'audited@example.com',
                'roles' => ['admin'],
            ])
            ->assertCreated();

        Log::shouldHaveReceived('notice')
            ->once()
            ->withArgs(fn (string $event, array $context): bool => $event === 'security.admin_user.created'
                && $context['actor_user_id'] === $actor->id
                && is_int($context['target_user_id'])
                && ! array_key_exists('password', $context));
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
