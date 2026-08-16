<?php

namespace App\Providers;

use App\Jobs\AnalyzeStockResearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->usesProtectedDatabase());
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('admin.login-code.request', function (Request $request): array {
            [$email, $ip, $emailAndIp] = $this->authenticationRateLimitKeys($request);

            return [
                Limit::perMinute(1)->by("admin-login-code-request-email-minute:{$email}"),
                Limit::perMinute(1)->by("admin-login-code-request-minute:{$emailAndIp}"),
                Limit::perHour(10)->by("admin-login-code-request-email-hour:{$email}"),
                Limit::perHour(30)->by("admin-login-code-request-ip-hour:{$ip}"),
            ];
        });

        RateLimiter::for('admin.login-code.verify', function (Request $request): array {
            [$email, $ip, $emailAndIp] = $this->authenticationRateLimitKeys($request);

            return [
                Limit::perMinute(5)->by("admin-login-code-verify-minute:{$emailAndIp}"),
                Limit::perHour(20)->by("admin-login-code-verify-email-hour:{$email}"),
                Limit::perHour(60)->by("admin-login-code-verify-ip-hour:{$ip}"),
            ];
        });

        RateLimiter::for('admin.password-login', function (Request $request): array {
            [$email, $ip, $emailAndIp] = $this->authenticationRateLimitKeys($request);

            return [
                Limit::perMinute(5)->by("admin-password-login-minute:{$emailAndIp}"),
                Limit::perHour(20)->by("admin-password-login-email-hour:{$email}"),
                Limit::perHour(60)->by("admin-password-login-ip-hour:{$ip}"),
            ];
        });

        RateLimiter::for('admin.profile.password', function (Request $request): array {
            $userAndIp = $this->authenticatedRateLimitKey($request);

            return [
                Limit::perMinute(3)->by("admin-profile-password-minute:{$userAndIp}"),
                Limit::perHour(10)->by("admin-profile-password-hour:{$userAndIp}"),
            ];
        });

        RateLimiter::for('admin.costly-operation', function (Request $request): array {
            $userAndIp = $this->authenticatedRateLimitKey($request);

            return [
                Limit::perMinute(10)->by("admin-costly-operation-minute:{$userAndIp}"),
                Limit::perHour(60)->by("admin-costly-operation-hour:{$userAndIp}"),
            ];
        });

        RateLimiter::for('stock-ai-research', function (AnalyzeStockResearch $job): Limit {
            return Limit::perMinute(20)->by("stock-ai-research-user:{$job->userId}");
        });
    }

    /**
     * @return array{string, string, string}
     */
    private function authenticationRateLimitKeys(Request $request): array
    {
        $input = $request->input('email');
        $normalizedEmail = is_string($input) ? Str::limit(Str::lower(trim($input)), 254, '') : '';
        $ip = $request->ip() ?? 'unknown';
        $emailFingerprint = hash('sha256', $normalizedEmail);
        $ipFingerprint = hash('sha256', $ip);

        return [
            $emailFingerprint,
            $ipFingerprint,
            hash('sha256', "{$normalizedEmail}|{$ip}"),
        ];
    }

    private function authenticatedRateLimitKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier() ?? 'guest';
        $ip = $request->ip() ?? 'unknown';

        return hash('sha256', "{$userId}|{$ip}");
    }

    private function usesProtectedDatabase(): bool
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        return ($connection['driver'] ?? null) === 'mysql'
            && ($connection['database'] ?? null) === 'stocks';
    }
}
