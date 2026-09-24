<?php

namespace App\Services;

use HashContext;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SensitiveParameter;

class PreviewSnapshotStream
{
    private const Magic = "STOCKS-PREVIEW-STREAM-1\n";

    private const MaximumFileBytes = 2_147_483_648;

    private const MaximumRecordBytes = 1_048_576;

    private const MaximumHeaderBytes = 4096;

    /**
     * @param  iterable<array{table: string, row: array<string, scalar|null>}>  $records
     * @param  array{source_app_id: string, target_app_id: string, source_commit: string, target_commit: string, nonce: string}  $context
     * @return array{sha256: string, records: int, bytes: int}
     */
    public function seal(iterable $records, array $context, string $publicKey, string $outputPath): array
    {
        $this->validateContext($context);
        $this->assertPath($outputPath);

        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Invalid snapshot stream recipient.');
        }

        $previousMask = umask(0077);

        try {
            $handle = @fopen($outputPath, 'xb');
        } finally {
            umask($previousMask);
        }

        if ($handle === false) {
            throw new RuntimeException('Refusing to replace an existing snapshot stream.');
        }

        $identity = fstat($handle);
        $complete = false;
        $state = null;
        $key = null;

        try {
            if (! chmod($outputPath, 0600) || ! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Could not protect the snapshot stream.');
            }

            $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $header = $this->encode([
                'format' => 'stocks-preview-stream-v1',
                'key' => bin2hex(sodium_crypto_box_seal($key, $publicKey)),
                'stream' => bin2hex($streamHeader),
                'compression' => 'zlib-per-record-v1',
            ]);
            sodium_memzero($key);
            $prefix = self::Magic.pack('N', strlen($header)).$header;
            $additionalData = hash('sha256', $prefix, true);
            $hash = hash_init('sha256');
            $bytes = 0;
            $count = 0;
            $this->write($handle, $prefix, $hash, $bytes);
            $this->push($handle, $state, 'C'.$this->encode($context), $additionalData, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, $hash, $bytes);

            foreach ($records as $record) {
                $this->validateRecord($record);
                $plaintext = $this->encode($record);

                if (strlen($plaintext) > self::MaximumRecordBytes) {
                    throw new InvalidArgumentException('Snapshot record exceeds one MiB; no records were truncated.');
                }

                $compressed = gzcompress($plaintext, 6);

                if ($compressed === false) {
                    throw new RuntimeException('Snapshot stream compression failed.');
                }

                $message = strlen($compressed) < strlen($plaintext) ? "R\x01".$compressed : "R\x00".$plaintext;
                $this->push($handle, $state, $message, $additionalData, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, $hash, $bytes);
                $count++;
            }

            $this->push($handle, $state, 'S'.$this->encode(['records' => $count]), $additionalData, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, $hash, $bytes);
            $this->push($handle, $state, '', $additionalData, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL, $hash, $bytes);

            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Could not persist the snapshot stream.');
            }

            $complete = true;

            return ['sha256' => hash_final($hash), 'records' => $count, 'bytes' => $bytes];
        } finally {
            if (is_string($key)) {
                sodium_memzero($key);
            }
            if (is_string($state)) {
                sodium_memzero($state);
            }
            fclose($handle);

            if (! $complete) {
                $this->removeOwnedOutput($outputPath, $identity);
            }
        }
    }

    /**
     * Fully consume this iterator inside the import transaction before committing.
     *
     * @param  array{source_app_id: string, target_app_id: string, source_commit: string, target_commit: string, nonce: string}  $expectedContext
     * @return iterable<array{table: string, row: array<string, scalar|null>}>
     */
    public function open(string $path, array $expectedContext, #[SensitiveParameter] string $keyPair, string $trustedSHA): iterable
    {
        $this->validateContext($expectedContext);
        $this->assertPath($path);

        if (strlen($keyPair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES || preg_match('/^[a-f0-9]{64}$/D', $trustedSHA) !== 1 || ! is_file($path)) {
            throw new InvalidArgumentException('Invalid snapshot stream recipient, digest or file.');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the snapshot stream.');
        }

        $state = null;
        $key = null;

        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('Snapshot stream is being written.');
            }

            $stat = fstat($handle);

            if ($stat === false || $stat['size'] > self::MaximumFileBytes || ($stat['mode'] & 0170000) !== 0100000) {
                throw new InvalidArgumentException('Invalid snapshot stream size or file type.');
            }

            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $handle, self::MaximumFileBytes + 1);

            if ($size === false || $size > self::MaximumFileBytes || $size !== $stat['size'] || ! hash_equals($trustedSHA, hash_final($hash)) || ! rewind($handle)) {
                throw new InvalidArgumentException('Snapshot stream authentication failed.');
            }

            $hash = hash_init('sha256');
            $bytes = 0;
            $magic = $this->read($handle, strlen(self::Magic), $hash, $bytes);

            if ($magic !== self::Magic) {
                throw new InvalidArgumentException('Unknown snapshot stream format.');
            }

            $lengthBytes = $this->read($handle, 4, $hash, $bytes);
            $headerLength = unpack('Nlength', $lengthBytes)['length'];

            if ($headerLength < 1 || $headerLength > self::MaximumHeaderBytes) {
                throw new InvalidArgumentException('Invalid snapshot stream header length.');
            }

            $headerBytes = $this->read($handle, $headerLength, $hash, $bytes);
            $header = $this->decode($headerBytes);

            if (! is_array($header) || array_keys($header) !== ['format', 'key', 'stream', 'compression']
                || $header['format'] !== 'stocks-preview-stream-v1' || $header['compression'] !== 'zlib-per-record-v1'
                || ! is_string($header['key']) || preg_match('/^[a-f0-9]{160}$/D', $header['key']) !== 1
                || ! is_string($header['stream']) || preg_match('/^[a-f0-9]{48}$/D', $header['stream']) !== 1) {
                throw new InvalidArgumentException('Invalid snapshot stream header.');
            }

            $key = sodium_crypto_box_seal_open(hex2bin($header['key']), $keyPair);

            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new InvalidArgumentException('Snapshot stream recipient mismatch.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(hex2bin($header['stream']), $key);
            sodium_memzero($key);
            $additionalData = hash('sha256', $magic.$lengthBytes.$headerBytes, true);
            [$message, $tag] = $this->pull($handle, $state, $additionalData, $hash, $bytes);

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || ! str_starts_with($message, 'C')
                || $this->decode(substr($message, 1)) !== $expectedContext) {
                throw new InvalidArgumentException('Snapshot stream context mismatch.');
            }

            $count = 0;
            $summarySeen = false;

            while (true) {
                [$message, $tag] = $this->pull($handle, $state, $additionalData, $hash, $bytes);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($message !== '' || ! $summarySeen || fread($handle, 1) !== '' || ! feof($handle)
                        || $bytes !== $size || ! hash_equals($trustedSHA, hash_final($hash))) {
                        throw new InvalidArgumentException('Invalid snapshot stream completion or trailing bytes.');
                    }

                    return;
                }

                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $summarySeen) {
                    throw new InvalidArgumentException('Invalid snapshot stream sequence.');
                }

                if (str_starts_with($message, 'S')) {
                    if ($this->decode(substr($message, 1)) !== ['records' => $count]) {
                        throw new InvalidArgumentException('Snapshot stream record count mismatch.');
                    }

                    $summarySeen = true;

                    continue;
                }

                if (strlen($message) < 3 || $message[0] !== 'R' || ! in_array($message[1], ["\x00", "\x01"], true)) {
                    throw new InvalidArgumentException('Invalid snapshot stream record frame.');
                }

                $plaintext = substr($message, 2);

                if ($message[1] === "\x01") {
                    $plaintext = @gzuncompress($plaintext, self::MaximumRecordBytes);
                }

                if ($plaintext === false || strlen($plaintext) > self::MaximumRecordBytes) {
                    throw new InvalidArgumentException('Snapshot record decompression exceeds its limit or is invalid.');
                }

                $record = $this->decode($plaintext);
                $this->validateRecord($record);
                $count++;

                yield $record;
            }
        } finally {
            if (is_string($key)) {
                sodium_memzero($key);
            }
            if (is_string($state)) {
                sodium_memzero($state);
            }
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function push($handle, string &$state, string $message, string $additionalData, int $tag, HashContext $hash, int &$bytes): void
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $message, $additionalData, $tag);
        $this->write($handle, pack('N', strlen($ciphertext)).$ciphertext, $hash, $bytes);
    }

    /**
     * @param  resource  $handle
     * @return array{string, int}
     */
    private function pull($handle, string &$state, string $additionalData, HashContext $hash, int &$bytes): array
    {
        $length = unpack('Nlength', $this->read($handle, 4, $hash, $bytes))['length'];

        if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
            || $length > self::MaximumRecordBytes + 2 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            throw new InvalidArgumentException('Invalid snapshot stream frame length.');
        }

        $message = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($handle, $length, $hash, $bytes), $additionalData);

        if ($message === false) {
            throw new InvalidArgumentException('Snapshot stream frame authentication failed.');
        }

        return $message;
    }

    /** @param resource $handle */
    private function write($handle, string $contents, HashContext $hash, int &$bytes): void
    {
        $length = strlen($contents);

        if ($bytes + $length > self::MaximumFileBytes) {
            throw new InvalidArgumentException('Snapshot stream exceeds two GiB; no records were truncated.');
        }

        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the snapshot stream.');
            }

            $offset += $written;
        }

        hash_update($hash, $contents);
        $bytes += $length;
    }

    /** @param resource $handle */
    private function read($handle, int $length, HashContext $hash, int &$bytes): string
    {
        if ($bytes + $length > self::MaximumFileBytes) {
            throw new InvalidArgumentException('Snapshot stream exceeds two GiB.');
        }

        $contents = '';

        while (strlen($contents) < $length) {
            $chunk = fread($handle, $length - strlen($contents));

            if ($chunk === false || $chunk === '') {
                throw new InvalidArgumentException('Snapshot stream was truncated.');
            }

            $contents .= $chunk;
        }

        hash_update($hash, $contents);
        $bytes += $length;

        return $contents;
    }

    private function validateRecord(mixed $record): void
    {
        if (! is_array($record) || count($record) !== 2 || ! isset($record['table'], $record['row'])
            || ! is_string($record['table']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $record['table']) !== 1
            || ! is_array($record['row']) || $record['row'] === []) {
            throw new InvalidArgumentException('Invalid snapshot stream record.');
        }

        foreach ($record['row'] as $column => $value) {
            if (! is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || (! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))) {
                throw new InvalidArgumentException('Invalid snapshot stream row value.');
            }
        }
    }

    private function validateContext(array $context): void
    {
        if (PHP_INT_SIZE < 8 || array_keys($context) !== ['source_app_id', 'target_app_id', 'source_commit', 'target_commit', 'nonce']
            || count(array_filter($context, is_string(...))) !== 5
            || preg_match('/^[0-9]{1,20}$/D', $context['source_app_id']) !== 1
            || preg_match('/^[0-9]{1,20}$/D', $context['target_app_id']) !== 1
            || $context['source_app_id'] === $context['target_app_id']
            || preg_match('/^[a-f0-9]{40}$/D', $context['source_commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $context['target_commit']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $context['nonce']) !== 1) {
            throw new InvalidArgumentException('Invalid snapshot stream context or unsupported integer size.');
        }
    }

    private function assertPath(string $path): void
    {
        $parent = dirname($path);
        $canonical = realpath($parent);

        if ($canonical === false || str_replace('\\', '/', $canonical) !== str_replace('\\', '/', $parent)
            || is_link($path) || ! is_dir($parent) || in_array(basename($path), ['.', '..', ''], true)
            || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Snapshot stream requires a canonical path without symbolic links.');
        }
    }

    /** @param array<string, int>|false $identity */
    private function removeOwnedOutput(string $path, array|false $identity): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);

        if ($identity !== false && $current !== false && ! is_link($path)
            && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino']) {
            @unlink($path);
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function decode(string $value): mixed
    {
        try {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid snapshot stream JSON.');
        }
    }
}
