<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\User;
use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewReleaseBundle;
use App\Services\PreviewSnapshotArchive;
use App\Services\PreviewSnapshotDatabase;
use App\Services\PreviewSnapshotStream;
use App\Services\PreviewSnapshotTransfer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PreviewSnapshotTransferTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->directory = sys_get_temp_dir().'/stocks-snapshot-test-'.bin2hex(random_bytes(10));
        mkdir($this->directory.'/private', 0700, true);
        mkdir($this->directory.'/framework/sessions', 0700, true);
        mkdir($this->directory.'/framework/cache/data', 0700, true);
        $this->directory = realpath($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_encrypted_backup_restore_and_old_session_invalidation(): void
    {
        [$transfer, $database, $ciphertext, $before] = $this->fixtures();
        file_put_contents($this->directory.'/framework/sessions/old-session', 'old-preview-admin');
        file_put_contents($this->directory.'/framework/sessions/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->directory.'/framework/cache/data/.gitignore', "*\n!.gitignore\n");
        $this->assertSame(1, $transfer->inspect($ciphertext, hash('sha256', $ciphertext))['depots']);
        $result = $transfer->import($ciphertext, hash('sha256', $ciphertext));
        $this->assertSame('imported-maintenance', $result['state']);
        $this->assertFileExists($this->directory.'/framework/down');
        $this->assertSame('Original Depot', Depot::firstOrFail()->name);
        $this->assertStringNotContainsString('preview-admin@stocks.invalid', file_get_contents($this->directory.'/private/before.bin'));
        $transfer->restore();
        $this->assertSame($before, $database->digest($database->read()));
        $transfer->finish();
        $this->assertFileDoesNotExist($this->directory.'/framework/down');
        $this->assertFileDoesNotExist($this->directory.'/framework/sessions/old-session');
        $this->assertFileExists($this->directory.'/private/sessions-before/old-session');
        foreach (['sessions', 'cache/data'] as $runtimeDirectory) {
            $this->assertSame("*\n!.gitignore\n", file_get_contents($this->directory.'/framework/'.$runtimeDirectory.'/.gitignore'));
        }
    }

    public function test_successful_release_preserves_data_and_refuses_replay(): void
    {
        [$transfer, $database, $ciphertext] = $this->fixtures();
        $result = $transfer->import($ciphertext, hash('sha256', $ciphertext));
        $transfer->finish();
        $this->assertSame($result['data_sha256'], $database->digest($database->read()));
        $this->expectException(RuntimeException::class);
        $transfer->import($ciphertext, hash('sha256', $ciphertext));
    }

    public function test_release_keeps_tracked_runtime_placeholders_verifiable(): void
    {
        [, $database, $ciphertext] = $this->fixtures();
        $root = $this->directory.'/release';
        foreach (['public', 'vendor', 'storage/framework/sessions', 'storage/framework/cache/data', '.stocks-preview-private/attempt'] as $folder) {
            mkdir($root.'/'.$folder, 0700, true);
        }
        $files = [
            'artisan' => '<?php',
            'public/index.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'storage/framework/sessions/.gitignore' => "*\n!.gitignore\n",
            'storage/framework/cache/data/.gitignore' => "*\n!.gitignore\n",
        ];
        foreach ($files as $path => $contents) {
            file_put_contents($root.'/'.$path, $contents);
            $files[$path] = hash('sha256', $contents);
        }
        file_put_contents($root.'/preview-release.json', json_encode([
            'format' => 'stocks-preview-release-v1',
            'commit' => str_repeat('b', 40),
            'files' => $files,
        ], JSON_THROW_ON_ERROR));
        $private = $root.'/.stocks-preview-private/attempt';
        foreach (glob($this->directory.'/private/*') as $source) {
            $destination = $private.'/'.basename($source);
            copy($source, $destination);
            chmod($destination, 0600);
        }
        $transfer = new PreviewSnapshotTransfer($database, new PreviewSnapshotArchive(new PreviewOriginalPolicy), realpath($private),
            $root.'/storage/framework/down', $root.'/storage/framework/sessions', $root.'/storage/framework/cache/data');

        $transfer->import($ciphertext, hash('sha256', $ciphertext));
        $transfer->finish();

        (new PreviewReleaseBundle)->verifyInstalled($root, str_repeat('b', 40), hash_file('sha256', $root.'/preview-release.json'));
        $this->assertFileExists($private.'/sessions-before/.gitignore');
        $this->assertFileExists($private.'/cache-before/.gitignore');
    }

    public function test_tampered_archive_does_not_modify_preview(): void
    {
        [$transfer, $database, $ciphertext, $before] = $this->fixtures();
        try {
            $transfer->import($ciphertext.'x', hash('sha256', $ciphertext));
            $this->fail('Tampering should fail.');
        } catch (\InvalidArgumentException) {
            $this->assertFileDoesNotExist($this->directory.'/framework/down');
            $this->assertFileDoesNotExist($this->directory.'/private/consumed.json');
            $this->assertSame($before, $database->digest($database->read()));
        }
    }

    public function test_release_resumes_when_marker_was_written_before_maintenance_removal(): void
    {
        [$transfer, , $ciphertext] = $this->fixtures();
        $transfer->import($ciphertext, hash('sha256', $ciphertext));
        $maintenance = file_get_contents($this->directory.'/framework/down');
        $transfer->finish();
        file_put_contents($this->directory.'/framework/down', $maintenance);
        $transfer->finish();
        $transfer->finish();
        $this->assertFileDoesNotExist($this->directory.'/framework/down');
    }

    public function test_interrupted_backup_can_release_verified_unchanged_database(): void
    {
        [$transfer, $database, $ciphertext, $before] = $this->fixtures();
        $request = json_decode(file_get_contents($this->directory.'/private/request.json'), true);
        file_put_contents($this->directory.'/private/consumed.json', json_encode(['ciphertext_sha256' => hash('sha256', $ciphertext), 'data_sha256' => str_repeat('f', 64)]));
        chmod($this->directory.'/private/consumed.json', 0600);
        file_put_contents($this->directory.'/framework/down', json_encode(['stocks_preview_snapshot' => $request['context']['nonce']]));
        $transfer->restore();
        $transfer->finish();
        $this->assertSame($before, $database->digest($database->read()));
        $this->assertFileDoesNotExist($this->directory.'/framework/down');
    }

    public function test_restore_refuses_business_edits_after_import(): void
    {
        [$transfer, , $ciphertext] = $this->fixtures();
        $transfer->import($ciphertext, hash('sha256', $ciphertext));
        Depot::firstOrFail()->update(['name' => 'Do not erase this change']);
        try {
            $transfer->restore();
            $this->fail('Changed data should prevent restore.');
        } catch (RuntimeException) {
            $this->assertSame('Do not erase this change', Depot::firstOrFail()->name);
            $this->assertFileExists($this->directory.'/framework/down');
        }
    }

    public function test_standalone_launcher_refuses_an_unverified_root_before_bootstrap(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/preview-snapshot.php'), 'import', $this->directory, $this->directory.'/private']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Snapshot stopped:', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/framework/down');
    }

    public function test_streamed_file_import_and_restore_preserve_original_backup(): void
    {
        [$transfer, $database, $ciphertext, $before] = $this->fixtures();
        $request = json_decode(file_get_contents($this->directory.'/private/request.json'), true);
        $keys = file_get_contents($this->directory.'/private/recipient.key');
        $tables = (new PreviewSnapshotArchive(new PreviewOriginalPolicy))->open($ciphertext, $request['context'], $keys, hash('sha256', $ciphertext));
        $path = $this->directory.'/original.snapshot';
        $sealed = (new PreviewSnapshotStream)->seal($database->recordsFromTables($tables), $request['context'], sodium_crypto_box_publickey($keys), $path);
        $this->assertSame(1, $transfer->inspectStream($path, $sealed['sha256'])['counts']['depots']);
        $result = $transfer->importStream($path, $sealed['sha256']);
        $this->assertSame('imported-maintenance', $result['state']);
        $this->assertSame('Original Depot', Depot::firstOrFail()->name);
        $transfer->restore();
        $this->assertSame($before, $database->digest($database->read()));
        $transfer->finish();
        $this->assertFileDoesNotExist($this->directory.'/framework/down');
    }

    /** @return array{PreviewSnapshotTransfer, PreviewSnapshotDatabase, string, string} */
    private function fixtures(): array
    {
        User::factory()->create(['id' => 1, 'email' => 'original@original.test', 'remember_token' => null]);
        Depot::factory()->create(['name' => 'Original Depot', 'account_number' => 'AT123456']);
        $database = new PreviewSnapshotDatabase(DB::getPdo(), new PreviewOriginalSchema);
        $original = $database->export();
        $database->import(array_fill_keys(array_keys($original), []), $database->digest($original));
        User::factory()->create(['id' => 1, 'email' => 'preview-admin@stocks.invalid', 'remember_token' => null]);
        $archive = new PreviewSnapshotArchive(new PreviewOriginalPolicy);
        $framework = realpath($this->directory.'/framework');
        $transfer = new PreviewSnapshotTransfer($database, $archive, realpath($this->directory.'/private'), $framework.'/down', $framework.'/sessions', $framework.'/cache/data');
        $request = $transfer->prepare(['source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40), 'target_commit' => str_repeat('b', 40), 'nonce' => str_repeat('c', 64)]);
        $ciphertext = $archive->seal($original, $request['context'], hex2bin($request['recipient']));

        return [$transfer, $database, $ciphertext, $database->digest($database->read())];
    }
}
