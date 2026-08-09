<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_include_security_headers_and_a_nonce_based_csp(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('X-Powered-By');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-".Vite::cspNonce()."'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function test_admin_responses_are_not_cacheable(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    public function test_hsts_is_only_sent_for_secure_production_requests(): void
    {
        config()->set('app.env', 'production');

        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_forwarded_https_is_ignored_from_untrusted_clients(): void
    {
        config()->set('app.env', 'production');

        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_forwarded_https_is_honored_only_from_an_explicitly_trusted_proxy(): void
    {
        config()->set([
            'app.env' => 'production',
            'security.trusted_proxies' => ['10.0.0.10'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_catch_all_proxy_configuration_is_ignored(): void
    {
        config()->set([
            'app.env' => 'production',
            'security.trusted_proxies' => ['*'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_equivalent_catch_all_cidrs_and_invalid_proxy_entries_are_ignored(): void
    {
        config()->set('app.env', 'production');

        foreach ([
            '192.0.2.44/0',
            '0.0.0.0/00',
            '2001:db8::44/0',
            '0:0:0:0:0:0:0:0/0',
            'not-an-ip-address',
        ] as $configuredProxy) {
            config()->set('security.trusted_proxies', [$configuredProxy]);

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->withHeader('X-Forwarded-Proto', 'https')
                ->get('http://localhost/')
                ->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_forwarded_host_is_ignored_even_from_a_trusted_proxy(): void
    {
        config()->set([
            'security.trusted_hosts' => ['localhost'],
            'security.trusted_proxies' => ['10.0.0.10'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-Host', 'attacker.example')
            ->get('http://localhost/admin')
            ->assertRedirect('http://localhost/admin/login');
    }

    public function test_untrusted_host_headers_are_rejected_before_redirects_are_generated(): void
    {
        config()->set('security.trusted_hosts', ['localhost']);

        $this->get('http://attacker.example/admin')
            ->assertBadRequest()
            ->assertHeaderMissing('Location');
    }

    public function test_exact_configured_host_is_allowed(): void
    {
        config()->set('security.trusted_hosts', ['stocks.example.com']);

        $this->get('http://stocks.example.com/')
            ->assertOk();
    }

    public function test_empty_trusted_host_configuration_fails_closed(): void
    {
        config()->set('security.trusted_hosts', []);

        $this->get('/')->assertServiceUnavailable();
    }

    public function test_apache_front_controller_does_not_redirect_before_host_validation(): void
    {
        $configuration = File::get(public_path('.htaccess'));

        $this->assertStringNotContainsString('R=301', $configuration);
        $this->assertStringNotContainsString('HTTP_HOST', $configuration);
    }
}
