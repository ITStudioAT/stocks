<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;

class PreviewSnapshotArchive
{
    private const MaximumBytes = 67_108_864;

    public function __construct(private ?PreviewOriginalPolicy $originalPolicy = null) {}

    /**
     * Pure in-memory format; does not read, write or import a database or file.
     * The caller must transfer the ciphertext digest over an authenticated channel.
     *
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @param  array{source_app_id: string, target_app_id: string, source_commit: string, target_commit: string, nonce: string}  $context
     */
    public function seal(array $tables, array $context, string $recipientPublicKey): string
    {
        $this->validateContext($context);
        if (strlen($recipientPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Invalid snapshot recipient.');
        }
        $payload = json_encode([
            'format' => $this->originalPolicy ? 'stocks-preview-original-v1' : 'stocks-preview-v1',
            'context' => $context,
            'tables' => $this->originalPolicy?->prepare($tables) ?? (new PreviewSnapshotPolicy)->sanitize($tables),
        ], JSON_THROW_ON_ERROR);
        if (strlen($payload) > self::MaximumBytes - SODIUM_CRYPTO_BOX_SEALBYTES) {
            throw new InvalidArgumentException('Snapshot exceeds the supported size.');
        }

        return sodium_crypto_box_seal($payload, $recipientPublicKey);
    }

    /**
     * @param  array{source_app_id: string, target_app_id: string, source_commit: string, target_commit: string, nonce: string}  $expectedContext
     * @return array<string, list<array<string, scalar|null>>>
     */
    public function open(string $ciphertext, array $expectedContext, string $recipientKeyPair, string $trustedDigest): array
    {
        $this->validateContext($expectedContext);
        if (strlen($ciphertext) > self::MaximumBytes || strlen($ciphertext) <= SODIUM_CRYPTO_BOX_SEALBYTES
            || strlen($recipientKeyPair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES
            || ! preg_match('/^[a-f0-9]{64}$/D', $trustedDigest)
            || ! hash_equals($trustedDigest, hash('sha256', $ciphertext))) {
            throw new InvalidArgumentException('Snapshot authentication failed.');
        }
        $payload = sodium_crypto_box_seal_open($ciphertext, $recipientKeyPair);
        if ($payload === false) {
            throw new InvalidArgumentException('Snapshot recipient mismatch.');
        }
        try {
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Snapshot format is invalid.');
        }
        if (! is_array($decoded) || ($decoded['format'] ?? null) !== ($this->originalPolicy ? 'stocks-preview-original-v1' : 'stocks-preview-v1')
            || ($decoded['context'] ?? null) !== $expectedContext || ! is_array($decoded['tables'] ?? null)) {
            throw new InvalidArgumentException('Snapshot context mismatch.');
        }

        return $this->originalPolicy?->prepare($decoded['tables']) ?? (new PreviewSnapshotPolicy)->sanitize($decoded['tables']);
    }

    /** @param array<string, string> $context */
    private function validateContext(array $context): void
    {
        if (array_keys($context) !== ['source_app_id', 'target_app_id', 'source_commit', 'target_commit', 'nonce']
            || ! ctype_digit($context['source_app_id']) || ! ctype_digit($context['target_app_id'])
            || $context['source_app_id'] === $context['target_app_id']
            || ! preg_match('/^[a-f0-9]{40}$/D', $context['source_commit'])
            || ! preg_match('/^[a-f0-9]{40}$/D', $context['target_commit'])
            || ! preg_match('/^[a-f0-9]{64}$/D', $context['nonce'])) {
            throw new InvalidArgumentException('Invalid snapshot context.');
        }
    }
}
