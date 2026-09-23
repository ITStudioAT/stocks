<?php

namespace Tests\Unit;

use App\Services\PreviewReleaseBundle;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class PreviewReleaseBundleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stocks-preview-bundle-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/source/public', 0700, true);
        file_put_contents($this->directory.'/source/public/index.php', '<?php echo "preview";');
    }

    public function test_exact_files_and_commit_survive_verified_round_trip(): void
    {
        $bundles = new PreviewReleaseBundle;
        $archive = $this->directory.'/release.zip';
        $commit = str_repeat('a', 40);
        $digest = $bundles->create($this->directory.'/source', $archive, $commit);
        $manifest = $bundles->extract($archive, $digest, $this->directory.'/extracted');
        $this->assertSame($commit, $manifest['commit']);
        $this->assertSame(file_get_contents($this->directory.'/source/public/index.php'), file_get_contents($this->directory.'/extracted/public/index.php'));
        $this->assertSame(hash_file('sha256', $archive), $digest);
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_archive_paths_are_rejected_before_any_extraction(string $path): void
    {
        $archive = $this->directory.'/unsafe.zip';
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE);
        $zip->addFromString('preview-release.json', json_encode(['format' => 'stocks-preview-release-v1', 'commit' => str_repeat('a', 40), 'files' => [$path => hash('sha256', 'payload')]], JSON_THROW_ON_ERROR));
        $zip->addFromString($path, 'payload');
        $zip->close();
        try {
            (new PreviewReleaseBundle)->extract($archive, hash_file('sha256', $archive), $this->directory.'/extracted');
            $this->fail('An unsafe archive was accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->directory.'/extracted');
        }
    }

    public static function unsafePaths(): array
    {
        return [['../escape'], ['/absolute'], ['C:/absolute'], ['.env'], ['.env.production'], ['bootstrap/cache/config.php'], ['storage/app/private/credentials.json'], ['public/hot']];
    }

    public function test_wrong_digest_is_rejected_before_extraction(): void
    {
        $archive = $this->directory.'/release.zip';
        (new PreviewReleaseBundle)->create($this->directory.'/source', $archive, str_repeat('a', 40));
        $this->expectException(RuntimeException::class);
        (new PreviewReleaseBundle)->extract($archive, str_repeat('0', 64), $this->directory.'/extracted');
    }

    public function test_builder_refuses_runtime_secrets(): void
    {
        file_put_contents($this->directory.'/source/.env', 'SECRET=do-not-ship');
        $this->expectException(RuntimeException::class);
        (new PreviewReleaseBundle)->create($this->directory.'/source', $this->directory.'/release.zip', str_repeat('a', 40));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }
}
