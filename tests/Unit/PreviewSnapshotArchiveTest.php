<?php

namespace Tests\Unit;

use App\Services\PreviewSnapshotArchive;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PreviewSnapshotArchiveTest extends TestCase
{
    public function test_snapshot_is_confidential_and_bound_to_exact_recipient_and_context(): void
    {
        $keys = sodium_crypto_box_keypair();
        $context = $this->context();
        $archive = new PreviewSnapshotArchive;
        $ciphertext = $archive->seal(['stock_holdings' => [['id' => 1, 'name' => 'Confidential holding']]], $context, sodium_crypto_box_publickey($keys));
        $this->assertStringNotContainsString('Confidential holding', $ciphertext);
        $result = $archive->open($ciphertext, $context, $keys, hash('sha256', $ciphertext));
        $this->assertSame('Confidential holding', $result['stock_holdings'][0]['name']);
    }

    #[DataProvider('mutations')]
    public function test_tampering_wrong_recipient_wrong_commit_and_replay_nonce_are_rejected(string $mutation): void
    {
        $keys = sodium_crypto_box_keypair();
        $context = $this->context();
        $archive = new PreviewSnapshotArchive;
        $ciphertext = $archive->seal([], $context, sodium_crypto_box_publickey($keys));
        $digest = hash('sha256', $ciphertext);
        switch ($mutation) {
            case 'ciphertext': $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
                break;
            case 'recipient': $keys = sodium_crypto_box_keypair();
                break;
            case 'digest': $digest = str_repeat('0', 64);
                break;
            case 'commit': $context['target_commit'] = str_repeat('b', 40);
                break;
            case 'nonce': $context['nonce'] = str_repeat('c', 64);
                break;
            case 'target': $context['target_app_id'] = '999';
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        $archive->open($ciphertext, $context, $keys, $digest);
    }

    public static function mutations(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], ['ciphertext', 'recipient', 'digest', 'commit', 'nonce', 'target']);
    }

    private function context(): array
    {
        return ['source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40), 'target_commit' => str_repeat('d', 40), 'nonce' => str_repeat('e', 64)];
    }
}
