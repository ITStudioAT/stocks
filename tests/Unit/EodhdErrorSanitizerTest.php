<?php

namespace Tests\Unit;

use App\Services\EodhdErrorSanitizer;
use Tests\TestCase;

class EodhdErrorSanitizerTest extends TestCase
{
    public function test_it_redacts_configured_and_unrecognized_eodhd_tokens(): void
    {
        config(['services.eodhd.key' => 'configured-secret-token']);

        $message = implode(' ', [
            'https://eodhd.test/api/eod?api_token=configured-secret-token&fmt=json',
            'https://eodhd.test/api/eod?api%5Ftoken=rotated-secret-token&fmt=json',
            'https://eodhd.test/api/eod?api%255Ftoken%253Dencoded-secret-token&fmt=json',
            '{"api_token":"json-secret-token"}',
            '{"apiToken":"camel-case-secret-token"}',
            '{"api%5Ftoken":"encoded-json-secret-token"}',
        ]);

        $sanitized = app(EodhdErrorSanitizer::class)->message($message);

        $this->assertStringNotContainsString('configured-secret-token', $sanitized);
        $this->assertStringNotContainsString('rotated-secret-token', $sanitized);
        $this->assertStringNotContainsString('encoded-secret-token', $sanitized);
        $this->assertStringNotContainsString('json-secret-token', $sanitized);
        $this->assertStringNotContainsString('camel-case-secret-token', $sanitized);
        $this->assertStringNotContainsString('encoded-json-secret-token', $sanitized);
        $this->assertSame(6, substr_count($sanitized, '[redacted]'));
        $this->assertStringContainsString('&fmt=json', $sanitized);
        $this->assertSame($sanitized, app(EodhdErrorSanitizer::class)->message($sanitized));
    }

    public function test_it_sanitizes_nested_payloads_without_changing_non_string_values(): void
    {
        $payload = [
            'error' => 'Request failed: api_token=secret-value',
            'nested' => [
                'message' => 'api_token => another-secret',
                'api_token' => 'structured-secret',
                'api%5Ftoken' => 'structured-encoded-secret',
                'apiToken' => 'structured-camel-case-secret',
                'attempts' => 3,
                'retry' => false,
            ],
        ];

        $sanitized = app(EodhdErrorSanitizer::class)->payload($payload);

        $this->assertSame('Request failed: api_token=[redacted]', $sanitized['error']);
        $this->assertSame('api_token => [redacted]', $sanitized['nested']['message']);
        $this->assertSame('[redacted]', $sanitized['nested']['api_token']);
        $this->assertSame('[redacted]', $sanitized['nested']['api%5Ftoken']);
        $this->assertSame('[redacted]', $sanitized['nested']['apiToken']);
        $this->assertSame(3, $sanitized['nested']['attempts']);
        $this->assertFalse($sanitized['nested']['retry']);
    }

    public function test_it_applies_length_limits_after_redaction(): void
    {
        config(['services.eodhd.key' => 'configured-secret-token']);

        $sanitized = app(EodhdErrorSanitizer::class)->message(
            'api_token=configured-secret-token '.str_repeat('x', 100),
            30,
        );

        $this->assertStringNotContainsString('configured-secret-token', $sanitized);
        $this->assertSame(30, mb_strlen($sanitized));
    }
}
