<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCodeMail;
use App\Models\Company;
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

    public function test_super_admin_payload_uses_the_active_company_as_selected_company(): void
    {
        Role::findOrCreate('super_admin');

        $userCompany = Company::factory()->create([
            'is_active' => false,
        ]);
        $activeCompany = Company::factory()->create([
            'company_name_1' => 'Active Company',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'company_id' => $userCompany->id,
        ]);
        $user->assignRole('super_admin');

        $this->actingAs($user)
            ->getJson('/admin/me')
            ->assertOk()
            ->assertJsonPath('user.selected_company_id', $activeCompany->id)
            ->assertJsonPath('user.selected_company_name', 'Active Company');
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
