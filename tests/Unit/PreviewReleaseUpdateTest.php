<?php

namespace Tests\Unit;

use App\Services\PreviewReleaseBundle;
use App\Services\PreviewReleaseUpdate;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewReleaseUpdateTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $archive;

    private string $digest;

    private string $oldCommit;

    private string $newCommit;

    private PreviewReleaseUpdate $update;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/stocks-preview-update-test-'.bin2hex(random_bytes(8));
        $source = $this->directory.'/source';
        $next = $this->directory.'/next';
        foreach ([$source, $next] as $directory) {
            mkdir($directory.'/public', 0700, true);
            mkdir($directory.'/vendor', 0700, true);
            mkdir($directory.'/storage/framework', 0700, true);
            file_put_contents($directory.'/artisan', '<?php');
            file_put_contents($directory.'/vendor/autoload.php', '<?php');
            file_put_contents($directory.'/storage/framework/.gitignore', '*');
        }
        file_put_contents($source.'/public/index.php', 'old release');
        file_put_contents($next.'/public/index.php', 'new release');
        $this->oldCommit = str_repeat('a', 40);
        $this->newCommit = str_repeat('b', 40);
        $bundles = new PreviewReleaseBundle;
        $oldArchive = $this->directory.'/old.zip';
        $oldDigest = $bundles->create($source, $oldArchive, $this->oldCommit);
        $this->root = $this->directory.'/app/public_html';
        mkdir(dirname($this->root), 0700, true);
        $bundles->extract($oldArchive, $oldDigest, $this->root);
        $this->root = realpath($this->root);
        mkdir($this->root.'/.stocks-preview-private', 0700);
        file_put_contents($this->root.'/.env', 'APP_KEY=original');
        file_put_contents($this->root.'/storage/framework/private-state', 'original');
        $marker = [
            'format' => 'stocks-preview-instance-v1', 'root' => $this->root,
            'source_app_id' => 'source', 'target_app_id' => 'target',
            'state' => 'active', 'commit' => $this->oldCommit,
            'manifest_sha256' => hash_file('sha256', $this->root.'/preview-release.json'),
        ];
        file_put_contents($this->root.'/storage/framework/stocks-preview-instance', json_encode($marker, JSON_THROW_ON_ERROR));
        $this->archive = $this->directory.'/new.zip';
        $this->digest = $bundles->create($next, $this->archive, $this->newCommit);
        $this->update = new PreviewReleaseUpdate([
            'canonicalTargetRoot' => $this->root,
            'sourceAppId' => 'source',
            'targetAppId' => 'target',
        ]);
    }

    public function test_update_releases_new_code_and_keeps_runtime_state(): void
    {
        $this->assertSame('ready', $this->update->inspect($this->archive, $this->digest, $this->root, fileowner($this->root), $this->oldCommit)['state']);
        $verified = false;
        $result = $this->update->apply($this->archive, $this->digest, $this->root, fileowner($this->root), $this->oldCommit,
            function (string $root) use (&$verified): void {
                $this->assertSame('new release', file_get_contents($root.'/public/index.php'));
                $this->assertFileExists($root.'/storage/framework/down');
                $verified = true;
            });

        $this->assertTrue($verified);
        $this->assertSame('released', $result['state']);
        $this->assertSame($this->newCommit, $result['commit']);
        $this->assertSame('APP_KEY=original', file_get_contents($this->root.'/.env'));
        $this->assertSame('original', file_get_contents($this->root.'/storage/framework/private-state'));
        $this->assertSame($this->newCommit, $this->marker()['commit']);
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down');
        $this->assertFileDoesNotExist($this->root.'/.stocks-preview-private/update.json');
    }

    public function test_failed_runtime_check_restores_previous_release(): void
    {
        try {
            $this->update->apply($this->archive, $this->digest, $this->root, fileowner($this->root), $this->oldCommit,
                function (): void {
                    throw new RuntimeException('Runtime check failed.');
                });
            $this->fail('A failed runtime check was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Runtime check failed.', $exception->getMessage());
        }

        $this->assertSame('old release', file_get_contents($this->root.'/public/index.php'));
        $this->assertSame('APP_KEY=original', file_get_contents($this->root.'/.env'));
        $this->assertSame('original', file_get_contents($this->root.'/storage/framework/private-state'));
        $this->assertSame($this->oldCommit, $this->marker()['commit']);
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down');
        $this->assertFileDoesNotExist($this->root.'/.stocks-preview-private/update.json');
    }

    public function test_recovery_releases_maintenance_when_update_stopped_before_file_swap(): void
    {
        $private = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private';
        $staging = $private.DIRECTORY_SEPARATOR.'release-'.str_repeat('c', 32);
        (new PreviewReleaseBundle)->extract($this->archive, $this->digest, $staging);
        $nonce = str_repeat('d', 64);
        $maintenance = $private.DIRECTORY_SEPARATOR.'update-maintenance-'.$nonce.'.json';
        file_put_contents($maintenance, json_encode(['stocks_preview_update' => $nonce], JSON_THROW_ON_ERROR));
        link($maintenance, $this->root.'/storage/framework/down');
        file_put_contents($private.DIRECTORY_SEPARATOR.'update.json', json_encode([
            'format' => 'stocks-preview-update-v1', 'root' => $this->root,
            'owner' => fileowner($this->root), 'old_marker' => $this->marker(),
            'old_commit' => $this->oldCommit,
            'old_digest' => hash_file('sha256', $this->root.'/preview-release.json'),
            'new_commit' => $this->newCommit,
            'new_digest' => hash_file('sha256', $staging.'/preview-release.json'),
            'staging' => $staging,
            'backup' => $private.DIRECTORY_SEPARATOR.'failed-'.str_repeat('e', 32),
            'rollback' => $private.DIRECTORY_SEPARATOR.'failed-'.str_repeat('f', 32),
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR));

        $this->update->recover($this->root, fileowner($this->root));

        $this->assertSame('old release', file_get_contents($this->root.'/public/index.php'));
        $this->assertSame($this->oldCommit, $this->marker()['commit']);
        $this->assertFileDoesNotExist($this->root.'/storage/framework/down');
        $this->assertFileDoesNotExist($private.DIRECTORY_SEPARATOR.'update.json');
    }

    private function marker(): array
    {
        return json_decode((string) file_get_contents($this->root.'/storage/framework/stocks-preview-instance'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }
}
