<?php

namespace Tests\Unit;

use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewSnapshotArchive;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PreviewOriginalPolicyTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_original_names_notes_account_numbers_and_password_hashes_survive(): void
    {
        $password = password_hash('original-user-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $tables = ['depots' => [['id' => 1, 'name' => 'Original Depot', 'account_number' => 'AT123', 'description' => 'Original note', 'account_balance' => '1234.5600']], 'users' => [['id' => 2, 'password' => $password, 'remember_token' => 'do-not-copy']]];
        $result = (new PreviewOriginalPolicy)->prepare($tables);
        $this->assertSame($tables['depots'], $result['depots']);
        $this->assertSame($password, $result['users'][0]['password']);
        $this->assertNull($result['users'][0]['remember_token']);
        $this->assertSame($result, (new PreviewOriginalPolicy)->prepare($result));
    }

    public function test_integration_secrets_are_removed_without_dropping_business_payload(): void
    {
        $result = (new PreviewOriginalPolicy(['live-secret-value']))->prepare(['stock_prices' => [[
            'id' => 1, 'source_url' => 'https://example.test/quote?api_token=live-secret-value&symbol=ABC',
            'raw_payload' => '{"price":123.45,"api_key":"another-secret","company":"Original Company"}',
        ]]]);
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('live-secret-value', $encoded);
        $this->assertStringNotContainsString('another-secret', $encoded);
        $this->assertStringContainsString('Original Company', $encoded);
        $this->assertStringContainsString('symbol=ABC', $encoded);
    }

    public function test_redacting_json_preserves_its_structure_and_scalar_values(): void
    {
        $value = json_encode([
            'enabled' => true,
            'limit' => 12345,
            'label' => 'true',
            'api_key' => 'live-secret',
            'callback' => 'https://example.test/?token=live-secret&active=true',
            'nested' => ['active' => false, 'count' => 42],
        ], JSON_THROW_ON_ERROR);

        $result = (new PreviewOriginalPolicy(['true', 'live-secret']))->prepare([
            'app_configs' => [['id' => 1, 'key' => 'example', 'value' => $value]],
        ]);

        $redacted = json_decode($result['app_configs'][0]['value'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($redacted['enabled']);
        $this->assertSame(12345, $redacted['limit']);
        $this->assertSame(false, $redacted['nested']['active']);
        $this->assertSame(42, $redacted['nested']['count']);
        $this->assertSame('[preview-redacted]', $redacted['label']);
        $this->assertSame('[preview-redacted]', $redacted['api_key']);
        $this->assertStringNotContainsString('live-secret', $result['app_configs'][0]['value']);
        $this->assertSame($result, (new PreviewOriginalPolicy(['true', 'live-secret']))->prepare($result));
    }

    public function test_original_archive_is_explicitly_separated_from_anonymized_format(): void
    {
        $keys = sodium_crypto_box_keypair();
        $context = ['source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40), 'target_commit' => str_repeat('b', 40), 'nonce' => str_repeat('c', 64)];
        $tables = ['depots' => [['id' => 1, 'name' => 'Original Depot']]];
        $archive = new PreviewSnapshotArchive(new PreviewOriginalPolicy);
        $ciphertext = $archive->seal($tables, $context, sodium_crypto_box_publickey($keys));
        $this->assertSame($tables, $archive->open($ciphertext, $context, $keys, hash('sha256', $ciphertext)));
        $this->expectException(InvalidArgumentException::class);
        (new PreviewSnapshotArchive)->open($ciphertext, $context, $keys, hash('sha256', $ciphertext));
    }

    public function test_runtime_tokens_cannot_be_added_to_original_snapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PreviewOriginalPolicy)->prepare(['personal_access_tokens' => []]);
    }
}
