<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCodeMail;
use App\Models\AdminLoginCode;
use App\Models\User;
use App\Services\AdminPasswordUpdater;
use App\Services\AdminSessionManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_request_and_atomically_consume_a_login_code_once(): void
    {
        Mail::fake();
        Log::spy();

        $user = $this->adminUser('admin@example.com', 'Admin-Password-123!');

        $this->postJson('/admin/login-code', [
            'email' => 'admin@example.com',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an eligible administrator account exists, a 6-digit login code has been sent.',
            );

        $code = $this->sentLoginCode();

        $this->postJson('/admin/verify-code', [
            'email' => 'admin@example.com',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, $user->auth_revision)
            ->assertSessionHas('password_hash_web', fn (mixed $hash): bool => is_string($hash) && $hash !== '');

        $this->assertAuthenticatedAs($user);

        Auth::logout();

        $this->postJson('/admin/verify-code', [
            'email' => 'admin@example.com',
            'code' => $code,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        Log::shouldHaveReceived('notice')->with(
            'security.admin_login_code.request_accepted',
            \Mockery::type('array'),
        )->once();
        Log::shouldHaveReceived('notice')->with(
            'security.admin_login_code.verification_succeeded',
            \Mockery::type('array'),
        )->once();
        Log::shouldHaveReceived('warning')->with(
            'security.admin_login_code.verification_failed',
            \Mockery::type('array'),
        )->once();
    }

    public function test_login_code_request_has_a_neutral_response_for_unknown_and_ineligible_accounts(): void
    {
        Mail::fake();
        Log::spy();

        User::factory()->create([
            'email' => 'user@example.com',
        ]);

        $unknown = $this->postJson('/admin/login-code', [
            'email' => 'missing@example.com',
        ])->assertOk();

        $ineligible = $this->postJson('/admin/login-code', [
            'email' => 'user@example.com',
        ])->assertOk();

        $this->assertSame($unknown->json('message'), $ineligible->json('message'));
        $this->assertNotNull($unknown->json('expires_at'));
        $this->assertNotNull($ineligible->json('expires_at'));
        Mail::assertNothingOutgoing();
        Log::shouldHaveReceived('warning')->with(
            'security.admin_login_code.request_rejected',
            \Mockery::type('array'),
        )->twice();
    }

    public function test_login_code_request_cooldown_uses_normalized_email_and_ip(): void
    {
        Mail::fake();

        $this->adminUser('admin@example.com', 'Admin-Password-123!');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/admin/login-code', [
                'email' => ' Admin@Example.com ',
            ])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->postJson('/admin/login-code', [
                'email' => 'admin@example.com',
            ])->assertStatus(429);

        Mail::assertQueued(AdminLoginCodeMail::class, 1);
    }

    public function test_repeated_code_request_keeps_the_active_code_and_neutral_expiry_metadata(): void
    {
        Mail::fake();
        $this->freezeTime();

        $user = $this->adminUser('admin@example.com', 'Admin-Password-123!');

        $firstResponse = $this->postJson('/admin/login-code', [
            'email' => $user->email,
        ])->assertOk();
        $firstCode = $this->sentLoginCode();

        $this->travel(2)->minutes();

        $repeatResponse = $this->postJson('/admin/login-code', [
            'email' => $user->email,
        ])->assertOk();
        $unknownResponse = $this->postJson('/admin/login-code', [
            'email' => 'missing@example.com',
        ])->assertOk();

        $this->assertNotSame($firstResponse->json('expires_at'), $repeatResponse->json('expires_at'));
        $this->assertSame($repeatResponse->json('expires_at'), $unknownResponse->json('expires_at'));
        Mail::assertQueued(AdminLoginCodeMail::class, 1);

        $this->postJson('/admin/verify-code', [
            'email' => $user->email,
            'code' => $firstCode,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_login_code_delivery_failure_keeps_the_response_neutral_and_removes_the_new_code(): void
    {
        $this->freezeTime();
        Log::spy();

        $user = $this->adminUser('admin@example.com', 'Admin-Password-123!');
        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andReturnSelf();
        Mail::shouldReceive('queue')
            ->once()
            ->andThrow(new RuntimeException('sensitive queue backend detail'));

        $eligibleResponse = $this->postJson('/admin/login-code', [
            'email' => $user->email,
        ])->assertOk();
        $unknownResponse = $this->postJson('/admin/login-code', [
            'email' => 'missing@example.com',
        ])->assertOk();

        $this->assertSame($eligibleResponse->json('message'), $unknownResponse->json('message'));
        $this->assertSame($eligibleResponse->json('expires_at'), $unknownResponse->json('expires_at'));
        $this->assertSame(0, AdminLoginCode::query()->where('email', $user->email)->count());
        Log::shouldHaveReceived('warning')->with(
            'security.admin_login_code.delivery_failed',
            \Mockery::on(fn (array $context): bool => ! array_key_exists('exception', $context)
                && ! array_key_exists('message', $context)
                && $context['user_id'] === $user->id),
        )->once();
    }

    public function test_login_code_is_locked_after_five_failed_attempts(): void
    {
        Mail::fake();
        Log::spy();

        $this->adminUser('admin@example.com', 'Admin-Password-123!');

        $this->postJson('/admin/login-code', [
            'email' => 'admin@example.com',
        ])->assertOk();

        $code = $this->sentLoginCode();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/admin/verify-code', [
                'email' => 'admin@example.com',
                'code' => '000000',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $loginCode = AdminLoginCode::query()->latest('id')->firstOrFail();

        $this->assertSame(5, $loginCode->attempts);
        $this->assertNotNull($loginCode->consumed_at);

        $this->travel(61)->seconds();

        $this->postJson('/admin/verify-code', [
            'email' => 'admin@example.com',
            'code' => $code,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        Log::shouldHaveReceived('warning')->with(
            'security.admin_login_code.verification_locked',
            \Mockery::type('array'),
        )->twice();
    }

    public function test_expired_and_consumed_login_codes_are_prunable_after_one_day(): void
    {
        $expired = AdminLoginCode::query()->create([
            'email' => 'expired@example.com',
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->subDays(2),
        ]);
        $consumed = AdminLoginCode::query()->create([
            'email' => 'consumed@example.com',
            'code_hash' => Hash::make('222222'),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => now()->subDays(2),
        ]);
        $active = AdminLoginCode::query()->create([
            'email' => 'active@example.com',
            'code_hash' => Hash::make('333333'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(2, (new AdminLoginCode)->pruneAll());
        $this->assertModelMissing($expired);
        $this->assertModelMissing($consumed);
        $this->assertModelExists($active);
    }

    public function test_admin_can_login_with_their_own_password(): void
    {
        Log::spy();

        $user = $this->adminUser('admin@example.com', 'Admin-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => ' Admin@Example.com ',
            'password' => 'Admin-Password-123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, $user->auth_revision)
            ->assertSessionMissing(AdminSessionManager::FallbackOriginIdSessionKey)
            ->assertSessionMissing(AdminSessionManager::FallbackOriginRevisionSessionKey)
            ->assertSessionHas('password_hash_web', fn (mixed $hash): bool => is_string($hash) && $hash !== '');

        $this->assertAuthenticatedAs($user);

        Log::shouldHaveReceived('notice')->withArgs(
            fn (string $event, array $context): bool => $event === 'security.admin_password_login.succeeded'
                && $context['user_id'] === $user->id
                && $context['used_super_admin_fallback'] === false,
        )->once();
    }

    public function test_any_current_database_super_admin_password_authenticates_the_entered_email_target(): void
    {
        Log::spy();

        $target = $this->adminUser('target@example.com', 'Target-Password-123!');
        $this->superAdminUser('first-super@example.com', 'First-Super-Password-123!');
        $credentialOwner = $this->superAdminUser('second-super@example.com', 'Second-Super-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => 'target@example.com',
            'password' => 'Second-Super-Password-123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'target@example.com')
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, $target->auth_revision)
            ->assertSessionHas(AdminSessionManager::FallbackOriginIdSessionKey, $credentialOwner->id)
            ->assertSessionHas(AdminSessionManager::FallbackOriginRevisionSessionKey, $credentialOwner->auth_revision);

        $this->assertAuthenticatedAs($target);
        $this->assertNotSame($credentialOwner->id, Auth::id());

        Log::shouldHaveReceived('notice')->withArgs(
            fn (string $event, array $context): bool => $event === 'security.admin_password_login.succeeded'
                && $context['user_id'] === $target->id
                && $context['used_super_admin_fallback'] === true,
        )->once();
    }

    public function test_malformed_and_non_current_super_admin_hashes_fail_closed_without_breaking_valid_fallback_login(): void
    {
        $target = $this->adminUser('target@example.com', 'Target-Password-123!');
        $malformedSuperAdmin = $this->superAdminUser('malformed-super@example.com', 'Discarded-Password-123!');
        $legacySuperAdmin = $this->superAdminUser('legacy-super@example.com', 'Discarded-Password-456!');
        $validSuperAdmin = $this->superAdminUser('valid-super@example.com', 'Valid-Super-Password-123!');

        DB::table('users')->where('id', $malformedSuperAdmin->id)->update([
            'password' => 'not-a-valid-password-hash',
        ]);
        DB::table('users')->where('id', $legacySuperAdmin->id)->update([
            'password' => password_hash('Legacy-Super-Password-123!', PASSWORD_ARGON2ID),
        ]);

        $this->postJson('/admin/password-login', [
            'email' => $target->email,
            'password' => 'Legacy-Super-Password-123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/admin/password-login', [
            'email' => $target->email,
            'password' => 'Valid-Super-Password-123!',
        ])
            ->assertOk()
            ->assertSessionHas(AdminSessionManager::FallbackOriginIdSessionKey, $validSuperAdmin->id)
            ->assertSessionHas(AdminSessionManager::FallbackOriginRevisionSessionKey, $validSuperAdmin->auth_revision);

        $this->assertAuthenticatedAs($target);
    }

    public function test_dormant_password_login_session_is_rejected_after_password_change(): void
    {
        $user = $this->adminUser('admin@example.com', 'Old-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => 'admin@example.com',
            'password' => 'Old-Password-123!',
        ])
            ->assertOk()
            ->assertSessionHas(AdminSessionManager::TargetRevisionSessionKey, 1);

        app(AdminPasswordUpdater::class)->update(
            $user,
            'Old-Password-123!',
            'New-Password-456!',
        );
        Auth::guard('web')->forgetUser();

        $this->getJson('/admin/me')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_fallback_session_is_rejected_when_origin_is_demoted(): void
    {
        [, $origin] = $this->establishFallbackSession();

        $origin->removeRole('super_admin');
        $origin->assignRole('admin');
        Auth::guard('web')->forgetUser();

        $this->getJson('/admin/me')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_fallback_session_is_rejected_when_origin_revision_changes(): void
    {
        [, $origin] = $this->establishFallbackSession();

        $origin->increment('auth_revision');
        Auth::guard('web')->forgetUser();

        $this->getJson('/admin/me')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_fallback_session_is_rejected_when_origin_is_deleted(): void
    {
        [, $origin] = $this->establishFallbackSession();

        $origin->delete();
        Auth::guard('web')->forgetUser();

        $this->getJson('/admin/me')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_another_regular_admin_password_cannot_authenticate_the_target(): void
    {
        $this->adminUser('target@example.com', 'Target-Password-123!');
        $this->adminUser('other@example.com', 'Other-Admin-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => 'target@example.com',
            'password' => 'Other-Admin-Password-123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_a_demoted_super_admin_password_is_no_longer_a_fallback_credential(): void
    {
        $this->adminUser('target@example.com', 'Target-Password-123!');
        $formerSuperAdmin = $this->superAdminUser('former-super@example.com', 'Former-Super-Password-123!');
        $formerSuperAdmin->removeRole('super_admin');
        $formerSuperAdmin->assignRole('admin');

        $this->postJson('/admin/password-login', [
            'email' => 'target@example.com',
            'password' => 'Former-Super-Password-123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_password_cannot_authenticate_an_ineligible_or_unknown_target(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
        ]);
        $this->superAdminUser('super@example.com', 'Super-Password-123!');

        foreach (['user@example.com', 'missing@example.com'] as $email) {
            $this->postJson('/admin/password-login', [
                'email' => $email,
                'password' => 'Super-Password-123!',
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'The provided credentials are invalid.');

            $this->assertGuest();
        }
    }

    public function test_configured_sa_pw_is_never_accepted_as_a_login_credential(): void
    {
        Log::spy();

        config()->set('services.super_admin.password', 'Configured-Only-Password-123!');

        $this->adminUser('target@example.com', 'Target-Password-123!');
        $this->superAdminUser('super@example.com', 'Database-Super-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => 'target@example.com',
            'password' => 'Configured-Only-Password-123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $event, array $context): bool => $event === 'security.admin_password_login.failed'
                && $context['used_super_admin_fallback'] === false
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Configured-Only-Password-123!'),
        )->once();
    }

    public function test_password_login_rate_limit_uses_normalized_email_and_ip(): void
    {
        $this->adminUser('admin@example.com', 'Admin-Password-123!');

        foreach (range(1, 5) as $attempt) {
            $email = $attempt % 2 === 0
                ? ' ADMIN@EXAMPLE.COM '
                : 'admin@example.com';

            $this->postJson('/admin/password-login', [
                'email' => $email,
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/admin/password-login', [
            'email' => 'admin@example.com',
            'password' => 'Admin-Password-123!',
        ])->assertStatus(429);
    }

    public function test_current_admin_payload_does_not_include_company_context(): void
    {
        $user = $this->superAdminUser('super@example.com', 'Super-Password-123!');

        $this->actingAs($user)
            ->getJson('/admin/me')
            ->assertOk()
            ->assertJsonMissingPath('user.company_id')
            ->assertJsonMissingPath('user.selected_company_id');
    }

    public function test_costly_operation_limiter_is_defined_with_user_and_ip_scoped_windows(): void
    {
        $user = $this->adminUser('admin@example.com', 'Admin-Password-123!');
        $limiter = RateLimiter::limiter('admin.costly-operation');

        $this->assertNotNull($limiter);

        $request = Request::create('/admin/tests/tickers', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.20',
        ]);
        $request->setUserResolver(fn (): User => $user);

        $limits = $limiter($request);

        $this->assertCount(2, $limits);
        $this->assertSame(10, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame(60, $limits[1]->maxAttempts);
        $this->assertSame(3600, $limits[1]->decaySeconds);
        $this->assertNotSame($limits[0]->key, $limits[1]->key);
        $this->assertSame(
            'admin-costly-operation-minute:'.hash('sha256', "{$user->id}|203.0.113.20"),
            $limits[0]->key,
        );
        $this->assertStringNotContainsString('203.0.113.20', $limits[0]->key);
    }

    private function adminUser(string $email, string $password): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function superAdminUser(string $email, string $password): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function sentLoginCode(): string
    {
        $code = null;

        Mail::assertQueued(AdminLoginCodeMail::class, function (AdminLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertIsString($code);

        return $code;
    }

    /** @return array{User, User} */
    private function establishFallbackSession(): array
    {
        $target = $this->adminUser('target@example.com', 'Target-Password-123!');
        $origin = $this->superAdminUser('super@example.com', 'Super-Password-123!');

        $this->postJson('/admin/password-login', [
            'email' => 'target@example.com',
            'password' => 'Super-Password-123!',
        ])->assertOk();

        $this->assertAuthenticatedAs($target);

        return [$target, $origin];
    }
}
