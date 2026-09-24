<?php

namespace App\Services;

use RuntimeException;

class PreviewSnapshotTransfer
{
    public function __construct(
        private PreviewSnapshotDatabase $database,
        private PreviewSnapshotArchive $archive,
        private string $directory,
        private string $maintenancePath,
        private string $sessionDirectory,
        private string $cacheDirectory,
    ) {}

    /** @param array{source_app_id: string, target_app_id: string, source_commit: string, target_commit: string, nonce: string} $context */
    public function prepare(array $context): array
    {
        $this->assertDirectory();
        $this->database->assertSchema();
        $this->database->assertEmpty();
        $keyPair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keyPair);
        $this->archive->seal([], $context, $publicKey);
        $this->writeNew('recipient.key', $keyPair);
        $request = ['context' => $context, 'recipient' => bin2hex($publicKey)];
        $this->writeNew('request.json', json_encode($request, JSON_THROW_ON_ERROR));
        $this->writeNew('prepared.json', json_encode([
            'protected_digest' => $this->database->protectedDigest(),
            'before_digest' => $this->database->digest($this->database->read()),
        ], JSON_THROW_ON_ERROR));

        return $request;
    }

    /** @return array<string, int> */
    public function inspect(string $ciphertext, string $trustedDigest): array
    {
        $tables = $this->open($ciphertext, $trustedDigest);
        $this->database->assertSchema();
        $this->database->assertEmpty();

        return array_map(count(...), $tables);
    }

    /** @return array{counts: array<string, int>, data_sha256: string, state: string} */
    public function import(string $ciphertext, string $trustedDigest): array
    {
        $tables = $this->open($ciphertext, $trustedDigest);
        $prepared = $this->readJson('prepared.json');
        if ($prepared['protected_digest'] !== $this->database->protectedDigest()) {
            throw new RuntimeException('Preview protected data changed since preparation. Prepare a fresh request.');
        }
        $this->database->assertEmpty();
        $dataDigest = $this->database->digest($tables);
        $request = $this->readJson('request.json');
        $this->writeNew('consumed.json', json_encode(['ciphertext_sha256' => $trustedDigest, 'data_sha256' => $dataDigest], JSON_THROW_ON_ERROR));
        $this->enterMaintenance($request['context']['nonce']);
        $backup = [
            'format' => 'stocks-preview-original-checkpoint-v1',
            'context' => $request['context'],
            'tables' => $this->database->read(),
            'protected_digest' => $prepared['protected_digest'],
        ];
        $beforeDigest = $this->database->digest($backup['tables']);
        if ($beforeDigest !== $prepared['before_digest']) {
            throw new RuntimeException('Preview application data changed since preparation.');
        }
        $backupContents = sodium_crypto_box_seal(json_encode($backup, JSON_THROW_ON_ERROR), sodium_crypto_box_publickey($this->readFile('recipient.key')));
        $this->writeNew('before.bin', $backupContents);
        $this->writeNew('before.sha256', hash('sha256', $backupContents));
        $this->database->import($tables, $beforeDigest, rehearsal: true);
        $this->writeNew('rehearsal.json', json_encode(['restored_original' => true], JSON_THROW_ON_ERROR));
        $actualDigest = $this->database->import($tables, $beforeDigest);
        $result = ['counts' => array_map(count(...), $tables), 'data_sha256' => $actualDigest, 'state' => 'imported-maintenance'];
        $this->writeNew('imported.json', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    /** Recover both an interrupted transaction and a completed but unreleased first-fill. */
    public function restore(): void
    {
        $this->assertUnreleased();
        $request = $this->readJson('request.json');
        $this->assertMaintenance($request['context']['nonce']);
        $prepared = $this->readJson('prepared.json');
        $consumed = $this->readJson('consumed.json');
        if ($this->database->digest($this->database->read()) === $prepared['before_digest']
            && $this->database->protectedDigest() === $prepared['protected_digest']) {
            if (! is_file($this->directory.'/restored.json')) {
                $this->writeNew('restored.json', json_encode(['restored_original' => true], JSON_THROW_ON_ERROR));
            }

            return;
        }
        $backup = $this->readFile('before.bin');
        if (! hash_equals($this->readFile('before.sha256'), hash('sha256', $backup))) {
            throw new RuntimeException('Preview checkpoint digest mismatch.');
        }
        $plaintext = sodium_crypto_box_seal_open($backup, $this->readFile('recipient.key'));
        if ($plaintext === false) {
            throw new RuntimeException('Preview backup decryption failed.');
        }
        $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        if ($decoded['context'] !== $request['context'] || $decoded['protected_digest'] !== $prepared['protected_digest']
            || $this->database->digest($decoded['tables']) !== $prepared['before_digest']) {
            throw new RuntimeException('Preview checkpoint identity mismatch.');
        }
        $current = $this->database->digest($this->database->read());
        if ($current === $consumed['data_sha256']) {
            $this->database->restore($decoded['tables'], $current, $prepared['protected_digest']);
        } elseif ($current !== $prepared['before_digest'] || $this->database->protectedDigest() !== $prepared['protected_digest']) {
            throw new RuntimeException('Preview recovery refuses changed or partial data.');
        }
        $this->database->assertEmpty();
        if (! is_file($this->directory.'/restored.json')) {
            $this->writeNew('restored.json', json_encode(['restored_original' => true], JSON_THROW_ON_ERROR));
        }
    }

    public function finish(): void
    {
        $this->assertDirectory();
        $alreadyReleased = is_file($this->directory.'/released.json');
        if ($alreadyReleased && ! file_exists($this->maintenancePath)) {
            $this->readJson('released.json');

            return;
        }
        $request = $this->readJson('request.json');
        $this->assertMaintenance($request['context']['nonce']);
        $prepared = $this->readJson('prepared.json');
        $expected = is_file($this->directory.'/restored.json') ? $prepared['before_digest'] : $this->readJson('imported.json')['data_sha256'];
        if ($this->database->digest($this->database->read()) !== $expected || $this->database->protectedDigest() !== $prepared['protected_digest']) {
            throw new RuntimeException('Preview data changed before release.');
        }
        $this->quarantineRuntimeDirectory($this->sessionDirectory, 'sessions-before');
        $this->quarantineRuntimeDirectory($this->cacheDirectory, 'cache-before');
        if (! $alreadyReleased) {
            $this->writeNew('released.json', json_encode(['data_sha256' => $expected], JSON_THROW_ON_ERROR));
        }
        if (! unlink($this->maintenancePath)) {
            throw new RuntimeException('Could not remove owned maintenance state.');
        }
    }

    /** @return array<string, list<array<string, scalar|null>>> */
    private function open(string $ciphertext, string $trustedDigest): array
    {
        $this->assertDirectory();
        if (file_exists($this->directory.'/consumed.json')) {
            throw new RuntimeException('This snapshot request has already been consumed.');
        }

        return $this->archive->open($ciphertext, $this->readJson('request.json')['context'], $this->readFile('recipient.key'), $trustedDigest);
    }

    private function enterMaintenance(string $nonce): void
    {
        if (is_link($this->maintenancePath) || file_exists($this->maintenancePath)) {
            throw new RuntimeException('Refusing unrelated preview maintenance state.');
        }
        $stream = fopen($this->maintenancePath, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Could not create preview maintenance state.');
        }
        try {
            $contents = json_encode(['time' => time(), 'retry' => 60, 'refresh' => 60, 'status' => 503, 'stocks_preview_snapshot' => $nonce], JSON_THROW_ON_ERROR);
            if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Could not persist preview maintenance state.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertMaintenance(string $nonce): void
    {
        if (is_link($this->maintenancePath) || ! is_file($this->maintenancePath)
            || (json_decode((string) file_get_contents($this->maintenancePath), true)['stocks_preview_snapshot'] ?? null) !== $nonce) {
            throw new RuntimeException('Preview maintenance ownership mismatch.');
        }
    }

    private function assertUnreleased(): void
    {
        $this->assertDirectory();
        if (file_exists($this->directory.'/released.json')) {
            throw new RuntimeException('Recovery is available only before this snapshot is released.');
        }
    }

    private function quarantineRuntimeDirectory(string $path, string $backupName): void
    {
        $framework = str_replace('\\', '/', dirname($this->maintenancePath));
        $normalized = str_replace('\\', '/', $path);
        if (! in_array($normalized, [$framework.'/sessions', $framework.'/cache/data'], true)
            || is_link($path) || (is_dir($path) && str_replace('\\', '/', realpath($path)) !== $normalized)) {
            throw new RuntimeException('Invalid preview session/cache directory.');
        }
        $destination = $this->directory.'/'.$backupName;
        if (! file_exists($destination) && is_dir($path) && ! rename($path, $destination)) {
            throw new RuntimeException('Could not quarantine previous preview sessions/cache.');
        }
        if (! is_dir($path) && ! mkdir($path, 0700)) {
            throw new RuntimeException('Could not recreate preview sessions/cache.');
        }
    }

    private function assertDirectory(): void
    {
        if (! is_dir($this->directory) || is_link($this->directory) || realpath($this->directory) !== $this->directory
            || (PHP_OS_FAMILY !== 'Windows' && ((fileperms($this->directory) & 0077) !== 0 || fileowner($this->directory) !== posix_geteuid()))) {
            throw new RuntimeException('Snapshot requires an owned private canonical directory.');
        }
    }

    private function writeNew(string $name, string $contents): void
    {
        $this->assertDirectory();
        $path = $this->directory.'/'.$name;
        if (is_link($path)) {
            throw new RuntimeException('Snapshot files cannot be symbolic links.');
        }
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Snapshot state already exists or cannot be written.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Could not persist private snapshot state.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function readFile(string $name): string
    {
        $this->assertDirectory();
        $path = $this->directory.'/'.$name;
        if (is_link($path) || ! is_file($path) || filesize($path) > 67_108_864
            || (PHP_OS_FAMILY !== 'Windows' && ((fileperms($path) & 0077) !== 0 || fileowner($path) !== posix_geteuid()))) {
            throw new RuntimeException('Invalid private snapshot state.');
        }

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private function readJson(string $name): array
    {
        return json_decode($this->readFile($name), true, 64, JSON_THROW_ON_ERROR);
    }
}
