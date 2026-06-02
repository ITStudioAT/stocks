<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_and_verify_a_login_code(): void
    {
        Mail::fake();

        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create([
            'email' => 'kron@naturwelt.at',
        ]);
        $user->syncRoles(['admin', 'super_admin']);

        $this->postJson('/admin/login-code', [
            'email' => 'kron@naturwelt.at',
        ])->assertOk();

        $code = null;
        Mail::assertSent(AdminLoginCodeMail::class, function (AdminLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/admin/verify-code', [
            'email' => 'kron@naturwelt.at',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'kron@naturwelt.at')
            ->assertJsonPath('user.roles.0', 'admin')
            ->assertJsonPath('user.roles.1', 'super_admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_login_with_password(): void
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole('admin');

        $this->postJson('/admin/password-login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com');

        $this->assertAuthenticatedAs($user);
    }

    public function test_current_admin_payload_does_not_include_company_context(): void
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->actingAs($user)
            ->getJson('/admin/me')
            ->assertOk()
            ->assertJsonMissingPath('user.company_id')
            ->assertJsonMissingPath('user.selected_company_id');
    }

    public function test_non_admin_cannot_login_with_password(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/admin/password-login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }
}
