<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

class AdminSessionManager
{
    public const TargetRevisionSessionKey = 'auth_revision_web';

    public const FallbackOriginIdSessionKey = 'auth_fallback_origin_id_web';

    public const FallbackOriginRevisionSessionKey = 'auth_fallback_origin_revision_web';

    public const OtpAuthenticatedAtSessionKey = 'auth_otp_authenticated_at_web';

    public const OtpPasswordInitializationLifetimeMinutes = 10;

    public function establish(
        Request $request,
        User $user,
        int $targetAuthRevision,
        ?int $fallbackOriginUserId = null,
        ?int $fallbackOriginAuthRevision = null,
    ): void {
        $this->establishSession(
            $request,
            $user,
            $targetAuthRevision,
            $fallbackOriginUserId,
            $fallbackOriginAuthRevision,
        );
        $request->session()->forget(self::OtpAuthenticatedAtSessionKey);
    }

    public function establishFromLoginCode(Request $request, User $user, int $targetAuthRevision): void
    {
        $this->establishSession($request, $user, $targetAuthRevision);
        $request->session()->put(self::OtpAuthenticatedAtSessionKey, now()->getTimestamp());
    }

    public function canInitializePassword(Request $request, ?User $user): bool
    {
        if (! $user || $user->password_initialized_at !== null || ! $request->user()?->is($user)) {
            return false;
        }

        if ($request->session()->get(self::TargetRevisionSessionKey) !== $user->auth_revision) {
            return false;
        }

        if ($request->session()->has(self::FallbackOriginIdSessionKey)
            || $request->session()->has(self::FallbackOriginRevisionSessionKey)) {
            return false;
        }

        $authenticatedAt = $request->session()->get(self::OtpAuthenticatedAtSessionKey);

        if (! is_int($authenticatedAt)) {
            return false;
        }

        $ageInSeconds = now()->getTimestamp() - $authenticatedAt;

        return $ageInSeconds >= 0
            && $ageInSeconds <= self::OtpPasswordInitializationLifetimeMinutes * 60;
    }

    private function establishSession(
        Request $request,
        User $user,
        int $targetAuthRevision,
        ?int $fallbackOriginUserId = null,
        ?int $fallbackOriginAuthRevision = null,
    ): void {
        $this->assertCompleteFallbackOrigin($fallbackOriginUserId, $fallbackOriginAuthRevision);

        $guard = Auth::guard('web');

        $guard->login($user);
        $request->session()->regenerate();

        $this->bind(
            $request,
            $user,
            $targetAuthRevision,
            $fallbackOriginUserId,
            $fallbackOriginAuthRevision,
        );
    }

    public function rebindAfterPasswordChange(Request $request, User $user): void
    {
        $guard = Auth::guard('web');

        $guard->setUser($user);
        $request->session()->regenerate(true);

        $this->bind($request, $user, $user->auth_revision);
        $request->session()->forget(self::OtpAuthenticatedAtSessionKey);
    }

    private function bind(
        Request $request,
        User $user,
        int $targetAuthRevision,
        ?int $fallbackOriginUserId = null,
        ?int $fallbackOriginAuthRevision = null,
    ): void {
        $guard = Auth::guard('web');

        $request->session()->put([
            self::TargetRevisionSessionKey => $targetAuthRevision,
            'password_hash_web' => $guard->hashPasswordForCookie($user->getAuthPassword()),
        ]);

        if ($fallbackOriginUserId === null) {
            $request->session()->forget([
                self::FallbackOriginIdSessionKey,
                self::FallbackOriginRevisionSessionKey,
            ]);

            return;
        }

        $request->session()->put([
            self::FallbackOriginIdSessionKey => $fallbackOriginUserId,
            self::FallbackOriginRevisionSessionKey => $fallbackOriginAuthRevision,
        ]);
    }

    private function assertCompleteFallbackOrigin(
        ?int $fallbackOriginUserId,
        ?int $fallbackOriginAuthRevision,
    ): void {
        if (($fallbackOriginUserId === null) === ($fallbackOriginAuthRevision === null)) {
            return;
        }

        throw new LogicException('Fallback authentication origin must include both user ID and revision.');
    }
}
