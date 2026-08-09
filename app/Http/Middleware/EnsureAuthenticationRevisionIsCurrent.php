<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminSessionManager;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Log;

class EnsureAuthenticationRevisionIsCurrent extends AuthenticateSession
{
    public function __construct(
        AuthFactory $auth,
    ) {
        parent::__construct($auth);
    }

    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next): mixed
    {
        if (! $request->hasSession() || ! $request->user()) {
            return parent::handle($request, $next);
        }

        if (! $this->targetRevisionIsCurrent($request)) {
            $this->reject($request, 'target_revision_missing_or_mismatched');
        }

        if (! $this->fallbackOriginIsCurrent($request)) {
            $this->reject($request, 'fallback_origin_missing_or_mismatched');
        }

        return parent::handle($request, $next);
    }

    private function targetRevisionIsCurrent(Request $request): bool
    {
        $storedRevision = $request->session()->get(AdminSessionManager::TargetRevisionSessionKey);

        return is_int($storedRevision)
            && $storedRevision === $request->user()->auth_revision;
    }

    private function fallbackOriginIsCurrent(Request $request): bool
    {
        $originUserId = $request->session()->get(AdminSessionManager::FallbackOriginIdSessionKey);
        $originRevision = $request->session()->get(AdminSessionManager::FallbackOriginRevisionSessionKey);

        if ($originUserId === null && $originRevision === null) {
            return true;
        }

        if (! is_int($originUserId) || ! is_int($originRevision)) {
            return false;
        }

        return User::query()
            ->whereKey($originUserId)
            ->where('auth_revision', $originRevision)
            ->whereHas('roles', function (Builder $query): void {
                $query
                    ->where('name', 'super_admin')
                    ->where('guard_name', 'web');
            })
            ->exists();
    }

    private function reject(Request $request, string $reason): never
    {
        Log::warning('security.admin_session.rejected', [
            'ip' => $request->ip(),
            'reason' => $reason,
            'user_id' => $request->user()?->getKey(),
        ]);

        $this->logout($request);
    }
}
