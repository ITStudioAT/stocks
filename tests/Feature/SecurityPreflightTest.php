<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityPreflightTest extends TestCase
{
    public function test_secure_production_configuration_passes(): void
    {
        $this->setSecureProductionConfiguration();

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight passed.')
            ->assertSuccessful();
    }

    public function test_unsafe_production_configuration_reports_each_failure(): void
    {
        $this->setSecureProductionConfiguration();

        config()->set([
            'app.debug' => true,
            'app.key' => 'invalid-key',
            'app.url' => 'http://stocks.example.com',
            'cache.limiter' => 'array',
            'mail.default' => 'log',
            'mail.from.address' => 'hello@example.com',
            'queue.default' => 'sync',
            'stocks.protected_admin.email' => 'admin@example.com',
            'security.trusted_hosts' => ['wrong.example.com'],
            'security.trusted_proxies' => ['*'],
            'security.headers.hsts_max_age' => 0,
            'session.driver' => 'file',
            'session.domain' => '.example.com',
            'session.encrypt' => false,
            'session.lifetime' => 10_080,
            'session.secure' => false,
        ]);

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight failed.')
            ->expectsOutputToContain('APP_DEBUG must be false.')
            ->expectsOutputToContain('APP_KEY must be valid for APP_CIPHER.')
            ->expectsOutputToContain('APP_URL must be an absolute HTTPS URL.')
            ->expectsOutputToContain('APP_TRUSTED_HOSTS must include the exact APP_URL host.')
            ->expectsOutputToContain('TRUSTED_PROXIES must contain only explicit proxy IP addresses or bounded CIDRs.')
            ->expectsOutputToContain('SECURITY_HSTS_MAX_AGE must be at least one year.')
            ->expectsOutputToContain('SESSION_DRIVER must use a bounded shared store, not files or cookies.')
            ->expectsOutputToContain('SESSION_LIFETIME must not exceed eight hours.')
            ->expectsOutputToContain('SESSION_ENCRYPT must be true.')
            ->expectsOutputToContain('Session cookies must be Secure and HttpOnly.')
            ->expectsOutputToContain('SESSION_DOMAIN must be null so session cookies remain host-only.')
            ->expectsOutputToContain('SUPER_ADMIN_EMAIL must be a real, non-reserved email address.')
            ->expectsOutputToContain('The production mailer must be configured, avoid log/array transports, and enforce TLS for remote SMTP.')
            ->expectsOutputToContain('MAIL_FROM_ADDRESS must be a real, non-reserved email address.')
            ->expectsOutputToContain('QUEUE_CONNECTION must use an asynchronous durable queue for login-code delivery.')
            ->expectsOutputToContain('CACHE_LIMITER must use a shared atomic cache store for rate limits.')
            ->assertFailed();
    }

    public function test_equivalent_catch_all_proxy_cidrs_fail_preflight(): void
    {
        foreach (['192.0.2.44/0', '0.0.0.0/00', '2001:db8::44/0', '0:0:0:0:0:0:0:0/0'] as $proxy) {
            $this->setSecureProductionConfiguration();
            config()->set('security.trusted_proxies', [$proxy]);

            $this->artisan('security:preflight')
                ->expectsOutputToContain('TRUSTED_PROXIES must contain only explicit proxy IP addresses or bounded CIDRs.')
                ->assertFailed();
        }
    }

    public function test_missing_mailer_configuration_fails_preflight(): void
    {
        $this->setSecureProductionConfiguration();
        config()->set('mail.default', 'missing-mailer');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('The production mailer must be configured, avoid log/array transports, and enforce TLS for remote SMTP.')
            ->assertFailed();
    }

    public function test_remote_smtp_requires_tls_through_nested_composite_mailers(): void
    {
        $this->setSecureProductionConfiguration();
        config()->set([
            'mail.default' => 'outer',
            'mail.mailers.outer' => [
                'transport' => 'roundrobin',
                'mailers' => ['inner'],
            ],
            'mail.mailers.inner' => [
                'transport' => 'failover',
                'mailers' => ['remote'],
            ],
            'mail.mailers.remote' => [
                'transport' => 'smtp',
                'host' => 'smtp.stocks.example.at',
                'scheme' => 'smtp',
                'url' => null,
            ],
        ]);

        $this->artisan('security:preflight')
            ->expectsOutputToContain('enforce TLS for remote SMTP')
            ->assertFailed();

        config()->set('mail.mailers.remote.scheme', 'smtps');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight passed.')
            ->assertSuccessful();
    }

    public function test_only_explicit_local_smtp_hosts_may_omit_tls(): void
    {
        $this->setSecureProductionConfiguration();
        config()->set('mail.mailers.smtp.host', 'localhost');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight passed.')
            ->assertSuccessful();

        config()->set('mail.mailers.smtp.host', 'smtp.internal');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('enforce TLS for remote SMTP')
            ->assertFailed();

        config()->set('mail.mailers.smtp.host', '::1');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight passed.')
            ->assertSuccessful();
    }

    public function test_remote_smtps_rejects_disabled_certificate_verification(): void
    {
        $this->setSecureProductionConfiguration();
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'url' => 'smtps://smtp.stocks.example.at:465?verify_peer=0',
        ]);

        $this->artisan('security:preflight')
            ->expectsOutputToContain('enforce TLS for remote SMTP')
            ->assertFailed();

        config()->set('mail.mailers.smtp.url', 'smtps://smtp.stocks.example.at:465');

        $this->artisan('security:preflight')
            ->expectsOutputToContain('Security preflight passed.')
            ->assertSuccessful();

        config()->set('mail.mailers.smtp.verify_peer_name', false);

        $this->artisan('security:preflight')
            ->expectsOutputToContain('enforce TLS for remote SMTP')
            ->assertFailed();
    }

    public function test_provider_base_urls_require_https_and_forbid_user_information(): void
    {
        $cases = [
            ['services.eodhd.base_url', 'http://eodhd.example.at/api', 'EODHD_BASE_URL'],
            ['services.eodhd.base_url', 'https://token@eodhd.example.at/api', 'EODHD_BASE_URL'],
            ['services.cloudways.deployment.base_url', 'http://cloudways.example.at/api/v2', 'CLOUDWAYS_API_BASE_URL'],
            ['services.cloudways.deployment.base_url', 'https://user:pass@cloudways.example.at/api/v2', 'CLOUDWAYS_API_BASE_URL'],
        ];

        foreach ($cases as [$configurationKey, $url, $failure]) {
            $this->setSecureProductionConfiguration();
            config()->set($configurationKey, $url);

            $this->artisan('security:preflight')
                ->expectsOutputToContain("{$failure} must be an absolute HTTPS URL without user information.")
                ->assertFailed();
        }
    }

    public function test_reserved_bootstrap_email_domains_fail_preflight(): void
    {
        foreach (['admin@stocks.example.com', 'admin@stocks.local'] as $email) {
            $this->setSecureProductionConfiguration();
            config()->set('stocks.protected_admin.email', $email);

            $this->artisan('security:preflight')
                ->expectsOutputToContain('SUPER_ADMIN_EMAIL must be a real, non-reserved email address.')
                ->assertFailed();
        }
    }

    private function setSecureProductionConfiguration(): void
    {
        config()->set([
            'app.debug' => false,
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://stocks.example.com',
            'cache.limiter' => 'database',
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => null,
                'url' => null,
                'host' => '127.0.0.1',
                'port' => 25,
            ],
            'mail.from.address' => 'noreply@stocks.example.at',
            'queue.default' => 'redis',
            'security.trusted_hosts' => ['stocks.example.com'],
            'security.trusted_proxies' => ['10.0.0.10'],
            'security.headers.hsts_max_age' => 31_536_000,
            'session.driver' => 'database',
            'session.domain' => null,
            'session.encrypt' => true,
            'session.http_only' => true,
            'session.lifetime' => 120,
            'session.same_site' => 'lax',
            'session.secure' => true,
            'stocks.protected_admin.email' => 'admin@stocks.example.at',
            'services.cloudways.deployment.base_url' => 'https://api.cloudways.com/api/v2',
            'services.eodhd.base_url' => 'https://eodhd.com/api',
        ]);
    }
}
