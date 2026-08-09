<?php

namespace App\Services;

use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProtectedAdminProvisioner
{
    private const ProtectedRoles = ['super_admin', 'admin'];

    private const RequiredRoles = ['super_admin', 'admin', 'user'];

    public function provision(): User
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        try {
            return DB::transaction(fn (): User => $this->provisionWithinTransaction(), attempts: 3);
        } finally {
            $permissionRegistrar->forgetCachedPermissions();
        }
    }

    private function provisionWithinTransaction(): User
    {
        foreach (self::RequiredRoles as $role) {
            Role::findOrCreate($role, 'web');
        }

        $protectedUsers = User::query()
            ->where('is_protected', true)
            ->lockForUpdate()
            ->get();

        if ($protectedUsers->count() > 1) {
            throw new RuntimeException('Multiple protected administrator accounts exist. Refusing to choose one.');
        }

        $user = $protectedUsers->first();
        $wasAlreadyProtected = $user !== null;
        $user ??= $this->configuredUser();
        $isNewUser = ! $user->exists;
        $currentRoles = $user->exists
            ? $user->getRoleNames()->sort()->values()->all()
            : [];
        $requiredProtectedRoles = collect(self::ProtectedRoles)->sort()->values()->all();
        $rolesWillChange = $user->exists && $currentRoles !== $requiredProtectedRoles;
        $attributes = [
            'email_verified_at' => now(),
            'is_protected' => true,
        ];

        if ($isNewUser) {
            $attributes = [
                ...$attributes,
                ...$this->configuredIdentity(),
                'password' => Str::password(40),
                'password_initialized_at' => null,
            ];
        }

        $mustRetireExistingPassword = $user->exists
            && (blank($user->password) || (! $wasAlreadyProtected && ! $user->hasRole('super_admin', 'web')));

        if ($mustRetireExistingPassword) {
            $attributes['password'] = Str::password(40);
            $attributes['password_initialized_at'] = null;
        }

        if ($user->exists && ($mustRetireExistingPassword || $rolesWillChange)) {
            $attributes['auth_revision'] = $user->auth_revision + 1;
        }

        $user->forceFill($attributes)->save();
        $user->syncRoles(self::ProtectedRoles);

        if ($isNewUser || $mustRetireExistingPassword || $rolesWillChange) {
            AdminLoginCode::query()
                ->where('email', $user->email)
                ->whereNull('consumed_at')
                ->delete();
        }

        return $user;
    }

    private function configuredUser(): User
    {
        $identity = $this->configuredIdentity();
        $matchingUsers = User::query()
            ->whereRaw('LOWER(email) = ?', [$identity['email']])
            ->lockForUpdate()
            ->get();

        if ($matchingUsers->count() > 1) {
            throw new RuntimeException('Multiple users match SUPER_ADMIN_EMAIL after normalization. Refusing to elevate any account.');
        }

        return $matchingUsers->first() ?? new User;
    }

    /**
     * @return array{email: string, first_name: string, last_name: string}
     */
    private function configuredIdentity(): array
    {
        $configuredEmail = config('stocks.protected_admin.email');

        if (! is_string($configuredEmail)) {
            throw new RuntimeException('SUPER_ADMIN_EMAIL must be configured before creating the protected admin account.');
        }

        $email = Str::lower(trim($configuredEmail));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('SUPER_ADMIN_EMAIL must be a valid email address before creating the protected admin account.');
        }

        return [
            'email' => $email,
            'first_name' => (string) config('stocks.protected_admin.first_name', 'Protected'),
            'last_name' => (string) config('stocks.protected_admin.last_name', 'Administrator'),
        ];
    }
}
