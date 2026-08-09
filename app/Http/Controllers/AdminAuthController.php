<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminPasswordLoginRequest;
use App\Http\Requests\SendAdminLoginCodeRequest;
use App\Http\Requests\VerifyAdminLoginCodeRequest;
use App\Models\User;
use App\Services\AdminLoginCodeBroker;
use App\Services\AdminPasswordAuthenticator;
use App\Services\AdminSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function requestCode(
        SendAdminLoginCodeRequest $request,
        AdminPasswordAuthenticator $authenticator,
        AdminLoginCodeBroker $loginCodes,
    ): JsonResponse {
        $email = $request->validated('email');
        $user = $authenticator->eligibleUser($email);
        $expiresAt = now()->addMinutes(10);

        if ($user) {
            $issueOutcome = $loginCodes->issue($user);

            if ($issueOutcome === AdminLoginCodeBroker::IssueDeliveryFailed) {
                Log::warning('security.admin_login_code.delivery_failed', $this->auditContext($request, $email, $user));
            } else {
                Log::notice('security.admin_login_code.request_accepted', [
                    ...$this->auditContext($request, $email, $user),
                    'reused_active_code' => $issueOutcome === AdminLoginCodeBroker::IssueReused,
                ]);
            }
        } else {
            $loginCodes->simulateIssueCost();
            Log::warning('security.admin_login_code.request_rejected', $this->auditContext($request, $email));
        }

        return response()->json([
            'message' => 'If an eligible administrator account exists, a 6-digit login code has been sent.',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function verifyCode(
        VerifyAdminLoginCodeRequest $request,
        AdminPasswordAuthenticator $authenticator,
        AdminLoginCodeBroker $loginCodes,
        AdminSessionManager $sessions,
    ): JsonResponse {
        $validated = $request->validated();
        $user = $authenticator->eligibleUser($validated['email']);
        $outcome = $loginCodes->consume($user, $validated['code']);

        if ($outcome !== AdminLoginCodeBroker::VerificationSuccess) {
            $event = $outcome === AdminLoginCodeBroker::VerificationLocked
                ? 'security.admin_login_code.verification_locked'
                : 'security.admin_login_code.verification_failed';

            Log::warning($event, $this->auditContext($request, $validated['email'], $user));

            throw ValidationException::withMessages([
                'code' => 'The login code is invalid or expired.',
            ]);
        }

        $sessions->establishFromLoginCode($request, $user, $user->auth_revision);
        Log::notice('security.admin_login_code.verification_succeeded', $this->auditContext($request, $validated['email'], $user));

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user, $sessions->canInitializePassword($request, $user)),
        ]);
    }

    public function passwordLogin(
        AdminPasswordLoginRequest $request,
        AdminPasswordAuthenticator $authenticator,
        AdminSessionManager $sessions,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $authenticator->authenticate($validated['email'], $validated['password']);

        if (! $result) {
            Log::warning('security.admin_password_login.failed', [
                ...$this->auditContext($request, $validated['email']),
                'used_super_admin_fallback' => false,
            ]);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        $user = $result['user'];
        $sessions->establish(
            $request,
            $user,
            $result['target_auth_revision'],
            $result['fallback_origin_user_id'],
            $result['fallback_origin_auth_revision'],
        );
        Log::notice('security.admin_password_login.succeeded', [
            ...$this->auditContext($request, $validated['email'], $user),
            'used_super_admin_fallback' => $result['used_super_admin_fallback'],
        ]);

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user, $sessions->canInitializePassword($request, $user)),
        ]);
    }

    public function me(Request $request, AdminSessionManager $sessions): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload(
                $request->user(),
                $sessions->canInitializePassword($request, $request->user()),
            ),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }

    /**
     * @return array{
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     roles: array<int, string>,
     *     can_initialize_password: bool,
     * }
     */
    private function userPayload(User $user, bool $canInitializePassword): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'can_initialize_password' => $canInitializePassword,
        ];
    }

    /**
     * @return array{email_fingerprint: string, ip: ?string, user_id: ?int}
     */
    private function auditContext(Request $request, string $email, ?User $user = null): array
    {
        return [
            'email_fingerprint' => hash('sha256', $email),
            'ip' => $request->ip(),
            'user_id' => $user?->id,
        ];
    }
}
