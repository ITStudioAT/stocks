<?php

namespace App\Services;

use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminPasswordUpdater
{
    public function __construct(
        private AdminSessionManager $sessions,
    ) {}

    public function update(
        User $user,
        #[\SensitiveParameter] ?string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
        ?Request $request = null,
    ): User {
        return DB::transaction(function () use (
            $user,
            $currentPassword,
            $newPassword,
            $request,
        ): User {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $isOtpInitialization = $lockedUser->password_initialized_at === null
                && $request !== null
                && $this->sessions->canInitializePassword($request, $lockedUser);

            if (! $isOtpInitialization
                && (! is_string($currentPassword) || ! Hash::check($currentPassword, $lockedUser->getAuthPassword()))) {
                throw ValidationException::withMessages([
                    'current_password' => 'The password is incorrect.',
                ]);
            }

            $lockedUser->forceFill([
                'password' => $newPassword,
                'auth_revision' => $lockedUser->auth_revision + 1,
                'password_initialized_at' => $lockedUser->password_initialized_at ?? now(),
            ])->save();

            AdminLoginCode::query()
                ->where('email', $lockedUser->email)
                ->whereNull('consumed_at')
                ->delete();

            return $lockedUser;
        }, attempts: 3);
    }
}
