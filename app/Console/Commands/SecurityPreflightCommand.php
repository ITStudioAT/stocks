<?php

namespace App\Console\Commands;

use App\Services\TrustedProxyConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

#[Signature('security:preflight')]
#[Description('Fail a production deployment when security-critical configuration is unsafe')]
class SecurityPreflightCommand extends Command
{
    public function handle(): int
    {
        $failures = $this->failures();

        if ($failures !== []) {
            $this->components->error('Security preflight failed.');

            foreach ($failures as $failure) {
                $this->line(" - {$failure}");
            }

            return self::FAILURE;
        }

        $this->components->info('Security preflight passed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function failures(): array
    {
        $failures = [];
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $trustedHosts = config('security.trusted_hosts', []);

        if (config('app.env') !== 'production') {
            $failures[] = 'APP_ENV must be production.';
        }

        if (config('app.debug')) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        if (blank(config('app.key'))) {
            $failures[] = 'APP_KEY must be configured.';
        } elseif (! $this->applicationKeyIsValid()) {
            $failures[] = 'APP_KEY must be valid for APP_CIPHER.';
        }

        if (parse_url($appUrl, PHP_URL_SCHEME) !== 'https' || ! is_string($appHost) || $appHost === '') {
            $failures[] = 'APP_URL must be an absolute HTTPS URL.';
        }

        if (! is_array($trustedHosts) || ! in_array($appHost, $trustedHosts, true)) {
            $failures[] = 'APP_TRUSTED_HOSTS must include the exact APP_URL host.';
        }

        if (! $this->protectedAdministratorEmailIsDeliverable()) {
            $failures[] = 'SUPER_ADMIN_EMAIL must be a real, non-reserved email address.';
        }

        if (TrustedProxyConfiguration::hasInvalidEntries(config('security.trusted_proxies', []))) {
            $failures[] = 'TRUSTED_PROXIES must contain only explicit proxy IP addresses or bounded CIDRs.';
        }

        if ((int) config('security.headers.hsts_max_age') < 31_536_000) {
            $failures[] = 'SECURITY_HSTS_MAX_AGE must be at least one year.';
        }

        if (! in_array(config('session.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            $failures[] = 'SESSION_DRIVER must use a bounded shared store, not files or cookies.';
        }

        if ((int) config('session.lifetime') > 480) {
            $failures[] = 'SESSION_LIFETIME must not exceed eight hours.';
        }

        if (! config('session.encrypt')) {
            $failures[] = 'SESSION_ENCRYPT must be true.';
        }

        if (! config('session.secure') || ! config('session.http_only')) {
            $failures[] = 'Session cookies must be Secure and HttpOnly.';
        }

        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $failures[] = 'SESSION_SAME_SITE must be lax or strict.';
        }

        if (config('session.domain') !== null) {
            $failures[] = 'SESSION_DOMAIN must be null so session cookies remain host-only.';
        }

        if (! $this->mailerConfigurationIsSafe((string) config('mail.default'))) {
            $failures[] = 'The production mailer must be configured, avoid log/array transports, and enforce TLS for remote SMTP.';
        }

        if (! $this->emailAddressIsNonReserved(config('mail.from.address'))) {
            $failures[] = 'MAIL_FROM_ADDRESS must be a real, non-reserved email address.';
        }

        if (! in_array(config('queue.default'), ['beanstalkd', 'database', 'redis', 'sqs'], true)) {
            $failures[] = 'QUEUE_CONNECTION must use an asynchronous durable queue for login-code delivery.';
        }

        $limiterStore = config('cache.limiter') ?: config('cache.default');
        $limiterDriver = config("cache.stores.{$limiterStore}.driver");

        if (! in_array($limiterDriver, ['database', 'dynamodb', 'memcached', 'redis'], true)) {
            $failures[] = 'CACHE_LIMITER must use a shared atomic cache store for rate limits.';
        }

        if (! $this->serviceBaseUrlIsSafe(config('services.eodhd.base_url'))) {
            $failures[] = 'EODHD_BASE_URL must be an absolute HTTPS URL without user information.';
        }

        if (! $this->serviceBaseUrlIsSafe(config('services.cloudways.deployment.base_url'))) {
            $failures[] = 'CLOUDWAYS_API_BASE_URL must be an absolute HTTPS URL without user information.';
        }

        return $failures;
    }

    private function applicationKeyIsValid(): bool
    {
        $key = config('app.key');

        if (! is_string($key)) {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key)
            && Encrypter::supported($key, (string) config('app.cipher'));
    }

    private function protectedAdministratorEmailIsDeliverable(): bool
    {
        return $this->emailAddressIsNonReserved(config('stocks.protected_admin.email'));
    }

    private function emailAddressIsNonReserved(mixed $email): bool
    {
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) substr(strrchr(trim($email), '@') ?: '', 1));
        $reservedDomains = ['example.com', 'example.net', 'example.org', 'localhost'];
        $matchesReservedDomain = collect($reservedDomains)->contains(
            fn (string $reservedDomain): bool => $domain === $reservedDomain
                || str_ends_with($domain, ".{$reservedDomain}"),
        );

        return $domain !== ''
            && ! $matchesReservedDomain
            && ! str_ends_with($domain, '.example')
            && ! str_ends_with($domain, '.invalid')
            && ! str_ends_with($domain, '.local')
            && ! str_ends_with($domain, '.localhost')
            && ! str_ends_with($domain, '.test');
    }

    /** @param array<int, string> $visitedMailers */
    private function mailerConfigurationIsSafe(string $mailer, array $visitedMailers = []): bool
    {
        if ($mailer === '' || in_array($mailer, $visitedMailers, true)) {
            return false;
        }

        $configuration = config("mail.mailers.{$mailer}");

        if (! is_array($configuration)) {
            return false;
        }

        $transport = $configuration['transport'] ?? null;

        if (! is_string($transport) || $transport === '' || in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if ($transport === 'smtp') {
            return $this->smtpMailerConfigurationIsSafe($configuration);
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return true;
        }

        $fallbackMailers = $configuration['mailers'] ?? null;

        if (! is_array($fallbackMailers) || $fallbackMailers === []) {
            return false;
        }

        $visitedMailers[] = $mailer;

        return collect($fallbackMailers)->every(fn (mixed $fallback): bool => is_string($fallback)
            && $this->mailerConfigurationIsSafe($fallback, $visitedMailers));
    }

    /** @param array<string, mixed> $configuration */
    private function smtpMailerConfigurationIsSafe(array $configuration): bool
    {
        $url = $configuration['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $parts = $this->parseUrl($url);

            if ($parts === null || ! is_string($parts['host'] ?? null)) {
                return false;
            }

            return $this->smtpEndpointIsSafe(
                $parts['host'],
                is_string($parts['scheme'] ?? null) ? $parts['scheme'] : null,
                $configuration,
                $parts,
            );
        }

        $host = $configuration['host'] ?? null;
        $scheme = $configuration['scheme'] ?? null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        return $this->smtpEndpointIsSafe($host, is_string($scheme) ? $scheme : null, $configuration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, int|string>  $urlParts
     */
    private function smtpEndpointIsSafe(
        string $host,
        ?string $scheme,
        array $configuration,
        array $urlParts = [],
    ): bool {
        $normalizedHost = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if (in_array($normalizedHost, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        return strtolower((string) $scheme) === 'smtps'
            && $this->smtpTlsVerificationIsSafe($configuration, $urlParts);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, int|string>  $urlParts
     */
    private function smtpTlsVerificationIsSafe(array $configuration, array $urlParts): bool
    {
        $options = $configuration;
        $query = $urlParts['query'] ?? null;

        if (is_string($query)) {
            parse_str($query, $queryOptions);
            $options = [...$options, ...$queryOptions];
        }

        return ! $this->containsUnsafeTlsOption($options);
    }

    /** @param array<array-key, mixed> $options */
    private function containsUnsafeTlsOption(array $options): bool
    {
        foreach ($options as $key => $value) {
            $normalizedKey = strtolower(str_replace('-', '_', (string) $key));

            if (in_array($normalizedKey, ['verify_peer', 'verify_peer_name'], true)
                && $this->configurationBoolean($value) !== true) {
                return true;
            }

            if ($normalizedKey === 'allow_self_signed' && $this->configurationBoolean($value) !== false) {
                return true;
            }

            if (is_array($value) && $this->containsUnsafeTlsOption($value)) {
                return true;
            }
        }

        return false;
    }

    private function configurationBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'on', 'true', 'yes' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function serviceBaseUrlIsSafe(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || trim($url) !== $url) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = $this->parseUrl($url);

        return $parts !== null
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }

    /** @return array<string, int|string>|null */
    private function parseUrl(string $url): ?array
    {
        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return null;
        }

        return is_array($parts) ? $parts : null;
    }
}
