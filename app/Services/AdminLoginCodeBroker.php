<?php

namespace App\Services;

use App\Mail\AdminLoginCodeMail;
use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminLoginCodeBroker
{
    public const VerificationSuccess = 'success';

    public const VerificationInvalid = 'invalid';

    public const VerificationLocked = 'locked';

    public const IssueQueued = 'queued';

    public const IssueReused = 'reused';

    public const IssueDeliveryFailed = 'delivery_failed';

    private const MaximumAttempts = 5;

    private const LifetimeMinutes = 10;

    private const DummyCodeHash = '$2y$12$3hLTtG9CjIkhb1urmNWYpudPX47g24ZjFlwKEzg7xDa/w4an6b74m';

    public function issue(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(self::LifetimeMinutes);
        $codeHash = Hash::make($code);

        $issue = DB::transaction(function () use ($user, $codeHash, $expiresAt): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $activeCode = AdminLoginCode::query()
                ->where('email', $lockedUser->email)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', self::MaximumAttempts)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($activeCode) {
                return [
                    'email' => null,
                    'login_code_id' => null,
                    'outcome' => self::IssueReused,
                ];
            }

            AdminLoginCode::query()
                ->where('email', $lockedUser->email)
                ->whereNull('consumed_at')
                ->delete();

            $loginCode = AdminLoginCode::query()->create([
                'email' => $lockedUser->email,
                'code_hash' => $codeHash,
                'attempts' => 0,
                'expires_at' => $expiresAt,
            ]);

            return [
                'email' => $lockedUser->email,
                'login_code_id' => $loginCode->getKey(),
                'outcome' => self::IssueQueued,
            ];
        }, attempts: 3);

        if ($issue['outcome'] === self::IssueReused) {
            return self::IssueReused;
        }

        try {
            Mail::to($issue['email'])->queue(new AdminLoginCodeMail(
                code: $code,
                expiresInMinutes: self::LifetimeMinutes,
            ));
        } catch (Throwable) {
            try {
                AdminLoginCode::query()
                    ->whereKey($issue['login_code_id'])
                    ->whereNull('consumed_at')
                    ->delete();
            } catch (Throwable) {
            }

            return self::IssueDeliveryFailed;
        }

        return self::IssueQueued;
    }

    public function consume(?User $user, #[\SensitiveParameter] string $code): string
    {
        if (! $user) {
            Hash::check($code, self::DummyCodeHash);

            return self::VerificationInvalid;
        }

        return DB::transaction(function () use ($user, $code): string {
            $loginCode = AdminLoginCode::query()
                ->where('email', $user->email)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $loginCode) {
                Hash::check($code, self::DummyCodeHash);

                return self::VerificationInvalid;
            }

            $codeMatches = Hash::check($code, $loginCode->code_hash);

            if ($loginCode->attempts >= self::MaximumAttempts) {
                return self::VerificationLocked;
            }

            if ($loginCode->consumed_at || $loginCode->expires_at->isPast()) {
                return self::VerificationInvalid;
            }

            if (! $codeMatches) {
                $loginCode->attempts++;

                if ($loginCode->attempts >= self::MaximumAttempts) {
                    $loginCode->consumed_at = now();
                }

                $loginCode->save();

                return $loginCode->attempts >= self::MaximumAttempts
                    ? self::VerificationLocked
                    : self::VerificationInvalid;
            }

            $loginCode->forceFill([
                'consumed_at' => now(),
            ])->save();

            return self::VerificationSuccess;
        }, attempts: 3);
    }

    public function simulateIssueCost(): void
    {
        Hash::make((string) random_int(100000, 999999));
    }
}
