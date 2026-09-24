<?php

namespace Tests\Unit;

use App\Services\PreviewSnapshotStream;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewSnapshotStreamTest extends TestCase
{
    private string $directory;

    private string $keyPair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stocks-stream-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
        $this->keyPair = sodium_crypto_box_keypair();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
        sodium_memzero($this->keyPair);
        parent::tearDown();
    }

    public function test_roundtrip_preserves_order_types_and_confidentiality_across_multiple_chunks(): void
    {
        $records = [
            ['table' => 'users', 'row' => ['id' => 1, 'password' => 'confidential-password-hash', 'remember_token' => null]],
            ['table' => 'stock_prices', 'row' => ['id' => '18446744073709551615', 'price' => '123.45678900', 'active' => true, 'number' => 1.0]],
            ['table' => 'stock_ai_researches', 'row' => ['id' => 'research-original', 'summary' => str_repeat('Private Ö research ', 10000)]],
        ];
        $path = $this->path();
        $stream = new PreviewSnapshotStream;
        $result = $stream->seal($records, $this->context(), sodium_crypto_box_publickey($this->keyPair), $path);
        $this->assertSame(3, $result['records']);
        $this->assertSame(filesize($path), $result['bytes']);
        $this->assertSame(hash_file('sha256', $path), $result['sha256']);
        $this->assertLessThan(10000, $result['bytes']);
        $this->assertStringNotContainsString('confidential-password-hash', file_get_contents($path));
        $this->assertSame($records, iterator_to_array($stream->open($path, $this->context(), $this->keyPair, $result['sha256']), false));

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($path) & 0777);
        }
    }

    public function test_empty_stream_still_requires_authenticated_completion(): void
    {
        $result = (new PreviewSnapshotStream)->seal([], $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
        $this->assertSame(0, $result['records']);
        $this->assertSame([], iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, $result['sha256'])));
    }

    public function test_incorrect_whole_file_digest_is_rejected_before_any_record_is_yielded(): void
    {
        $this->sealFixture();
        $yielded = 0;

        try {
            foreach ((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, str_repeat('0', 64)) as $record) {
                $yielded++;
            }
            $this->fail('Untrusted file digest was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $yielded);
        }
    }

    #[DataProvider('fileMutations')]
    public function test_authenticated_stream_rejects_corruption_and_invalid_sequence_even_with_updated_file_digest(string $mutation): void
    {
        $this->sealFixture();
        [$prefix, $frames] = $this->frames(file_get_contents($this->path()));

        switch ($mutation) {
            case 'ciphertext':
                $frames[1][10] = chr(ord($frames[1][10]) ^ 1);
                break;
            case 'header':
                $prefix[30] = chr(ord($prefix[30]) ^ 1);
                break;
            case 'reordered':
                [$frames[1], $frames[2]] = [$frames[2], $frames[1]];
                break;
            case 'duplicated':
                array_splice($frames, 2, 0, [$frames[1]]);
                break;
            case 'dropped':
                array_splice($frames, 1, 1);
                break;
            case 'missing-final':
                array_pop($frames);
                break;
            case 'truncated-final':
                $frames[array_key_last($frames)] = substr($frames[array_key_last($frames)], 0, -1);
                break;
            case 'trailing':
                $frames[] = 'unapproved-trailing-byte';
                break;
            case 'frame-length':
                $frames[1] = pack('N', 2_000_000).'x';
                break;
        }

        file_put_contents($this->path(), $prefix.implode('', $frames));
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, hash_file('sha256', $this->path())));
    }

    public static function fileMutations(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], ['ciphertext', 'header', 'reordered', 'duplicated', 'dropped', 'missing-final', 'truncated-final', 'trailing', 'frame-length']);
    }

    #[DataProvider('contextMutations')]
    public function test_wrong_context_or_recipient_is_rejected_before_records_are_yielded(string $field): void
    {
        $result = $this->sealFixture();
        $context = $this->context();
        $keys = $this->keyPair;

        if ($field === 'recipient') {
            $keys = sodium_crypto_box_keypair();
        } else {
            $context[$field] = str_ends_with($field, 'app_id') ? '999' : str_repeat('f', $field === 'nonce' ? 64 : 40);
        }

        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $context, $keys, $result['sha256']));
    }

    public static function contextMutations(): array
    {
        return array_map(fn (string $field): array => [$field], ['source_app_id', 'target_app_id', 'source_commit', 'target_commit', 'nonce', 'recipient']);
    }

    public function test_existing_output_is_never_modified_or_removed(): void
    {
        file_put_contents($this->path(), 'existing-output');

        try {
            $this->sealFixture();
            $this->fail('Existing output was accepted.');
        } catch (RuntimeException) {
            $this->assertSame('existing-output', file_get_contents($this->path()));
        }
    }

    public function test_output_is_private_at_creation_even_under_permissive_umask(): void
    {
        $previousMask = umask(0000);

        try {
            $records = (function (): Generator {
                if (PHP_OS_FAMILY !== 'Windows') {
                    $this->assertSame(0600, fileperms($this->path()) & 0777);
                }
                yield ['table' => 'users', 'row' => ['id' => 1]];
            })();
            (new PreviewSnapshotStream)->seal($records, $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
            $this->assertSame(0000, umask());
        } finally {
            umask($previousMask);
        }
    }

    public function test_failed_generator_removes_only_its_new_output(): void
    {
        $records = (function (): Generator {
            yield ['table' => 'users', 'row' => ['id' => 1]];
            throw new RuntimeException('Fixture interrupted.');
        })();

        try {
            (new PreviewSnapshotStream)->seal($records, $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
            $this->fail('Interrupted generator was accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->path());
        }
    }

    public function test_oversized_or_invalid_record_removes_output_without_truncating_it(): void
    {
        foreach ([
            ['table' => 'users', 'row' => ['id' => 1, 'note' => str_repeat('x', 1_048_576)]],
            ['table' => 'users', 'row' => ['id' => ['nested']]],
            ['table' => '../users', 'row' => ['id' => 1]],
            ['table' => 'users', 'row' => ['id' => INF]],
        ] as $record) {
            try {
                (new PreviewSnapshotStream)->seal([$record], $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
                $this->fail('Invalid record was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertFileDoesNotExist($this->path());
            }
        }
    }

    #[DataProvider('invalidAuthenticatedFrames')]
    public function test_bounded_decoder_rejects_authenticated_compression_bombs_counts_and_missing_final_markers(string $mutation): void
    {
        $record = json_encode(['table' => 'users', 'row' => ['id' => 1]], JSON_THROW_ON_ERROR);
        $messages = [
            ["R\x00".$record, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
            ['S{"records":1}', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
            ['', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],
        ];

        switch ($mutation) {
            case 'bomb':
                $messages[0][0] = "R\x01".gzcompress(str_repeat('x', 1_048_577));
                break;
            case 'bad-zlib':
                $messages[0][0] = "R\x01not-compressed";
                break;
            case 'count':
                $messages[1][0] = 'S{"records":2}';
                break;
            case 'count-type':
                $messages[1][0] = 'S{"records":"1"}';
                break;
            case 'missing-summary':
                array_splice($messages, 1, 1);
                break;
            case 'missing-final-tag':
                $messages[2][1] = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                break;
            case 'nonempty-final':
                $messages[2][0] = 'unexpected';
                break;
            case 'record-after-summary':
                array_splice($messages, 2, 0, [$messages[0]]);
                break;
        }

        $this->writeAuthenticatedFixture($messages);
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, hash_file('sha256', $this->path())));
    }

    public static function invalidAuthenticatedFrames(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], ['bomb', 'bad-zlib', 'count', 'count-type', 'missing-summary', 'missing-final-tag', 'nonempty-final', 'record-after-summary']);
    }

    public function test_header_length_is_bounded_before_allocation(): void
    {
        file_put_contents($this->path(), "STOCKS-PREVIEW-STREAM-1\n".pack('N', 2_000_000).'x');
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, hash_file('sha256', $this->path())));
    }

    public function test_symbolic_link_input_and_output_are_refused_without_touching_the_target(): void
    {
        $target = $this->directory.DIRECTORY_SEPARATOR.'target';
        file_put_contents($target, 'protected');

        if (! @symlink($target, $this->path())) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }

        try {
            $this->sealFixture();
            $this->fail('Symlink output was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame('protected', file_get_contents($target));
        }

        $this->expectException(InvalidArgumentException::class);
        iterator_to_array((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, hash_file('sha256', $target)));
    }

    public function test_thousands_of_records_are_processed_without_materializing_the_dataset(): void
    {
        $generated = 0;
        $records = (function () use (&$generated): Generator {
            for ($id = 1; $id <= 4000; $id++) {
                $generated++;
                yield ['table' => 'stock_ai_researches', 'row' => ['id' => 'research-'.$id, 'summary' => str_repeat('x', 16_384).$id]];
            }
        })();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $result = (new PreviewSnapshotStream)->seal($records, $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
        $this->assertSame(4000, $generated);
        $this->assertSame(4000, $result['records']);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before);

        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $count = 0;

        foreach ((new PreviewSnapshotStream)->open($this->path(), $this->context(), $this->keyPair, $result['sha256']) as $record) {
            $count++;
            $this->assertSame('research-'.$count, $record['row']['id']);
        }

        $this->assertSame(4000, $count);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    /** @return array{sha256: string, records: int, bytes: int} */
    private function sealFixture(): array
    {
        return (new PreviewSnapshotStream)->seal([
            ['table' => 'users', 'row' => ['id' => 1, 'name' => 'original']],
            ['table' => 'users', 'row' => ['id' => 2, 'name' => 'second']],
        ], $this->context(), sodium_crypto_box_publickey($this->keyPair), $this->path());
    }

    /** @return array{string, list<string>} */
    private function frames(string $contents): array
    {
        $offset = strlen("STOCKS-PREVIEW-STREAM-1\n");
        $headerLength = unpack('Nlength', substr($contents, $offset, 4))['length'];
        $offset += 4 + $headerLength;
        $prefix = substr($contents, 0, $offset);
        $frames = [];

        while ($offset < strlen($contents)) {
            $length = unpack('Nlength', substr($contents, $offset, 4))['length'];
            $frames[] = substr($contents, $offset, 4 + $length);
            $offset += 4 + $length;
        }

        return [$prefix, $frames];
    }

    /** @param list<array{string, int}> $messages */
    private function writeAuthenticatedFixture(array $messages): void
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $header = json_encode([
            'format' => 'stocks-preview-stream-v1',
            'key' => bin2hex(sodium_crypto_box_seal($key, sodium_crypto_box_publickey($this->keyPair))),
            'stream' => bin2hex($streamHeader),
            'compression' => 'zlib-per-record-v1',
        ], JSON_THROW_ON_ERROR);
        $prefix = "STOCKS-PREVIEW-STREAM-1\n".pack('N', strlen($header)).$header;
        $additionalData = hash('sha256', $prefix, true);
        $contents = $prefix;
        array_unshift($messages, ['C'.json_encode($this->context(), JSON_THROW_ON_ERROR), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE]);

        foreach ($messages as [$message, $tag]) {
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $message, $additionalData, $tag);
            $contents .= pack('N', strlen($ciphertext)).$ciphertext;
        }

        file_put_contents($this->path(), $contents);
    }

    private function path(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'snapshot.bin';
    }

    private function context(): array
    {
        return ['source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40), 'target_commit' => str_repeat('b', 40), 'nonce' => str_repeat('c', 64)];
    }
}
