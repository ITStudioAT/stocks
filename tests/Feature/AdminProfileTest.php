<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCodeMail;
use App\Models\AdminLoginCode;
use App\Models\User;
use App\Services\AdminPasswordUpdater;
use App\Services\AdminSessionManager;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_update_their_name(): void
    {
        $user = $this->adminUser();

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

    public function test_admin_can_update_password_with_current_password_and_confirmation(): void
    {
        $user = $this->adminUser();
        Event::fake([OtherDeviceLogout::class]);

        $this->postJson('/admin/password-login', [
            'email' => $user->email,
            'password' => 'Old-Password-123!',
        ])->assertOk();

        $this->patchJson('/admin/profile/password', [
            'current_password' => 'Old-Password-123!',
            'password' => 'New-Secure-Password-456!',
            'password_confirmation' => 'New-Secure-Password-456!',
        ])
            ->assertOk()
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, 2)
            ->assertSessionMissing(AdminSessionManager::FallbackOriginIdSessionKey)
            ->assertSessionMissing(AdminSessionManager::FallbackOriginRevisionSessionKey);

        $updatedUser = $user->fresh();

        $this->assertTrue(Hash::check('New-Secure-Password-456!', $updatedUser->password));
        $this->assertSame(2, $updatedUser->auth_revision);
        $this->assertAuthenticatedAs($user);
        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event): bool => $event->user->is($user));

        Auth::guard('web')->forgetUser();
        $this->getJson('/admin/me')->assertOk();
    }

    public function test_recent_otp_session_can_initialize_password_once_and_invalidates_outstanding_codes(): void
    {
        $user = $this->adminUser([
            'password_initialized_at' => null,
        ]);
        Event::fake([OtherDeviceLogout::class]);

        $this->loginWithCode($user)
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', true)
            ->assertSessionHas(
                AdminSessionManager::OtpAuthenticatedAtSessionKey,
                fn (mixed $value): bool => is_int($value),
            );

        AdminLoginCode::query()->create([
            'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->patchJson('/admin/profile/password', [
            'password' => 'Initialized-Password-456!',
            'password_confirmation' => 'Initialized-Password-456!',
        ])
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', false)
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, 2)
            ->assertSessionMissing(AdminSessionManager::OtpAuthenticatedAtSessionKey);

        $updatedUser = $user->fresh();

        $this->assertNotNull($updatedUser->password_initialized_at);
        $this->assertSame(2, $updatedUser->auth_revision);
        $this->assertTrue(Hash::check('Initialized-Password-456!', $updatedUser->password));
        $this->assertSame(
            0,
            AdminLoginCode::query()->where('email', $user->email)->whereNull('consumed_at')->count(),
        );
        Event::assertDispatched(OtherDeviceLogout::class);

        $this->patchJson('/admin/profile/password', [
            'password' => 'Replay-Password-789!',
            'password_confirmation' => 'Replay-Password-789!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(2, $user->fresh()->auth_revision);
        $this->assertTrue(Hash::check('Initialized-Password-456!', $user->fresh()->password));
    }

    public function test_expired_otp_session_cannot_initialize_password_without_current_password(): void
    {
        $user = $this->adminUser([
            'password_initialized_at' => null,
        ]);
        $originalPassword = $user->password;

        $this->loginWithCode($user)
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', true);

        $this->travel(AdminSessionManager::OtpPasswordInitializationLifetimeMinutes + 1)->minutes();

        $this->getJson('/admin/me')
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', false);

        try {
            app(AdminPasswordUpdater::class)->update(
                $user,
                null,
                'Direct-Expired-Password-456!',
                app(Request::class),
            );
            $this->fail('The password updater accepted expired OTP evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }

        $this->patchJson('/admin/profile/password', [
            'password' => 'Expired-Session-Password-456!',
            'password_confirmation' => 'Expired-Session-Password-456!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $updatedUser = $user->fresh();

        $this->assertNull($updatedUser->password_initialized_at);
        $this->assertSame(1, $updatedUser->auth_revision);
        $this->assertSame($originalPassword, $updatedUser->password);
    }

    public function test_password_updater_rechecks_initialization_state_after_acquiring_the_user_lock(): void
    {
        $user = $this->adminUser([
            'password_initialized_at' => null,
        ]);

        $this->loginWithCode($user)->assertOk();

        $passwords = app(AdminPasswordUpdater::class);
        $otpRequest = app(Request::class);

        $passwords->update($user, null, 'First-Initialized-Password-456!', $otpRequest);

        try {
            $passwords->update($user, null, 'Concurrent-Replay-Password-789!', $otpRequest);
            $this->fail('A concurrent initialization replay was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }

        $updatedUser = $user->fresh();

        $this->assertSame(2, $updatedUser->auth_revision);
        $this->assertTrue(Hash::check('First-Initialized-Password-456!', $updatedUser->password));
    }

    public function test_password_authenticated_uninitialized_user_cannot_skip_current_password(): void
    {
        $user = $this->adminUser([
            'password_initialized_at' => null,
        ]);

        $this->postJson('/admin/password-login', [
            'email' => $user->email,
            'password' => 'Old-Password-123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', false);

        $this->patchJson('/admin/profile/password', [
            'password' => 'Skipped-Current-Password-456!',
            'password_confirmation' => 'Skipped-Current-Password-456!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertNull($user->fresh()->password_initialized_at);
    }

    public function test_fallback_authenticated_uninitialized_user_cannot_skip_current_password(): void
    {
        $target = $this->adminUser([
            'password_initialized_at' => null,
        ]);
        Role::findOrCreate('super_admin');
        $superAdmin = User::factory()->create([
            'password' => Hash::make('Super-Admin-Password-123!'),
        ]);
        $superAdmin->assignRole('super_admin');

        $this->postJson('/admin/password-login', [
            'email' => $target->email,
            'password' => 'Super-Admin-Password-123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', false);

        $this->patchJson('/admin/profile/password', [
            'password' => 'Skipped-Current-Password-456!',
            'password_confirmation' => 'Skipped-Current-Password-456!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertNull($target->fresh()->password_initialized_at);
    }

    public function test_initialized_user_cannot_reenter_the_otp_initialization_flow(): void
    {
        $user = $this->adminUser();

        $this->loginWithCode($user)
            ->assertOk()
            ->assertJsonPath('user.can_initialize_password', false);

        $this->patchJson('/admin/profile/password', [
            'password' => 'No-Reinitialization-Password-456!',
            'password_confirmation' => 'No-Reinitialization-Password-456!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    public function test_password_update_rejects_an_incorrect_current_password(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Wrong-Password-123!',
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'New-Secure-Password-456!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->auth_revision);
    }

    public function test_password_update_requires_a_matching_confirmation(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Old-Password-123!',
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'Different-Secure-Password-789!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    public function test_password_update_rejects_weak_or_reused_passwords(): void
    {
        $user = $this->adminUser();

        foreach (['short', 'Old-Password-123!'] as $password) {
            $this->actingAs($user)
                ->patchJson('/admin/profile/password', [
                    'current_password' => 'Old-Password-123!',
                    'password' => $password,
                    'password_confirmation' => $password,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    public function test_password_update_caps_password_input_lengths(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => str_repeat('A', 1025),
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'New-Secure-Password-456!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $oversizedPassword = str_repeat('Aa1!', 257);

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Old-Password-123!',
                'password' => $oversizedPassword,
                'password_confirmation' => $oversizedPassword,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_updater_rechecks_current_password_inside_the_lock(): void
    {
        $user = $this->adminUser();
        $passwords = app(AdminPasswordUpdater::class);

        $passwords->update($user, 'Old-Password-123!', 'First-New-Password-456!');

        try {
            $passwords->update($user, 'Old-Password-123!', 'Second-New-Password-789!');
            $this->fail('A stale current password was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }

        $updatedUser = $user->fresh();

        $this->assertTrue(Hash::check('First-New-Password-456!', $updatedUser->password));
        $this->assertSame(2, $updatedUser->auth_revision);
    }

    public function test_super_admin_fallback_password_does_not_replace_current_password_confirmation(): void
    {
        $target = $this->adminUser();

        Role::findOrCreate('super_admin');
        $superAdmin = User::factory()->create([
            'password' => Hash::make('Super-Admin-Password-123!'),
        ]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($target)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Super-Admin-Password-123!',
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'New-Secure-Password-456!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Old-Password-123!', $target->fresh()->password));
    }

    public function test_stale_session_password_hash_is_rejected_after_password_change(): void
    {
        $user = $this->adminUser();
        $oldPasswordHash = $user->password;

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Old-Password-123!',
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'New-Secure-Password-456!',
            ])
            ->assertOk();

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->getJson('/admin/me')
            ->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_authenticated_session_without_revision_is_rejected(): void
    {
        $user = $this->adminUser();

        $this->be($user)
            ->getJson('/admin/me')
            ->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_authenticated_session_with_mismatched_revision_is_rejected(): void
    {
        $user = $this->adminUser();

        $this->be($user)
            ->withSession([
                AdminSessionManager::TargetRevisionSessionKey => $user->auth_revision + 1,
            ])
            ->getJson('/admin/me')
            ->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_password_update_is_rate_limited_per_user_and_ip(): void
    {
        $user = $this->adminUser();

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($user)
                ->patchJson('/admin/profile/password', [
                    'current_password' => 'Wrong-Password-123!',
                    'password' => 'New-Secure-Password-456!',
                    'password_confirmation' => 'New-Secure-Password-456!',
                ])
                ->assertUnprocessable();
        }

        $this->actingAs($user)
            ->patchJson('/admin/profile/password', [
                'current_password' => 'Old-Password-123!',
                'password' => 'New-Secure-Password-456!',
                'password_confirmation' => 'New-Secure-Password-456!',
            ])
            ->assertStatus(429);
    }

    /** @param array<string, mixed> $attributes */
    private function adminUser(array $attributes = []): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'last_name' => 'Old',
            'first_name' => 'Name',
            'password' => Hash::make('Old-Password-123!'),
            ...$attributes,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function loginWithCode(User $user): TestResponse
    {
        Mail::fake();

        $this->postJson('/admin/login-code', [
            'email' => $user->email,
        ])->assertOk();

        $code = null;
        Mail::assertQueued(AdminLoginCodeMail::class, function (AdminLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertIsString($code);

        return $this->postJson('/admin/verify-code', [
            'email' => $user->email,
            'code' => $code,
        ]);
    }
}
