<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AdminPasswordAuthenticator
{
    private const EligibleRoles = ['admin', 'super_admin'];

    private const DummyPasswordHash = '$2y$12$3hLTtG9CjIkhb1urmNWYpudPX47g24ZjFlwKEzg7xDa/w4an6b74m';

    public function eligibleUser(string $email): ?User
    {
        $user = User::query()
            ->with('roles')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user?->hasAnyRole(self::EligibleRoles)) {
            return null;
        }

        return $user;
    }

    /**
     * @return array{
     *     user: User,
     *     target_auth_revision: int,
     *     used_super_admin_fallback: bool,
     *     fallback_origin_user_id: ?int,
     *     fallback_origin_auth_revision: ?int,
     * }|null
     */
    public function authenticate(string $email, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->eligibleUser($email);
        $superAdministrators = $this->superAdministrators();
        $dummyPasswordHash = $superAdministrators->first()?->getAuthPassword() ?? self::DummyPasswordHash;
        $ownPasswordMatches = $this->passwordMatches(
            $password,
            $user?->getAuthPassword() ?? $dummyPasswordHash,
        );
        $fallbackOrigin = $this->matchingSuperAdministrator($password, $superAdministrators);

        if (! $user) {
            return null;
        }

        if (! $ownPasswordMatches && ! $fallbackOrigin) {
            return null;
        }

        return [
            'user' => $user,
            'target_auth_revision' => $user->auth_revision,
            'used_super_admin_fallback' => ! $ownPasswordMatches,
            'fallback_origin_user_id' => $ownPasswordMatches ? null : $fallbackOrigin?->getKey(),
            'fallback_origin_auth_revision' => $ownPasswordMatches ? null : $fallbackOrigin?->auth_revision,
        ];
    }

    /** @return Collection<int, User> */
    private function superAdministrators(): Collection
    {
        return User::query()
            ->whereHas('roles', function (Builder $query): void {
                $query
                    ->where('name', 'super_admin')
                    ->where('guard_name', 'web');
            })
            ->orderBy('id')
            ->get(['id', 'password', 'auth_revision']);
    }

    /** @param Collection<int, User> $superAdministrators */
    private function matchingSuperAdministrator(
        #[\SensitiveParameter] string $password,
        Collection $superAdministrators,
    ): ?User {
        $matchingSuperAdministrator = null;

        $superAdministrators->each(function (User $superAdministrator) use ($password, &$matchingSuperAdministrator): void {
            $candidateMatches = $this->passwordMatches($password, $superAdministrator->getAuthPassword());

            if ($candidateMatches && ! $matchingSuperAdministrator) {
                $matchingSuperAdministrator = $superAdministrator;
            }
        });

        return $matchingSuperAdministrator;
    }

    private function passwordMatches(
        #[\SensitiveParameter] string $password,
        string $passwordHash,
    ): bool {
        try {
            if (! Hash::isHashed($passwordHash)) {
                $this->burnDummyBcryptWork($password);

                return false;
            }

            return Hash::check($password, $passwordHash);
        } catch (Throwable) {
            $this->burnDummyBcryptWork($password);

            return false;
        }
    }

    private function burnDummyBcryptWork(#[\SensitiveParameter] string $password): void
    {
        password_verify($password, self::DummyPasswordHash);
    }
}
