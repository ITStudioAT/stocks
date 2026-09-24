<?php

namespace Tests\Unit;

use App\Services\PreviewFileSwap;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewFileSwapTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $candidate;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stocks-preview-swap-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/app/public_html/.stocks-preview-private', 0700, true);
        $this->root = realpath($this->directory.'/app/public_html');
        $private = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private';
        $this->candidate = $private.DIRECTORY_SEPARATOR.'release-'.str_repeat('a', 32);
        $this->backup = $private.DIRECTORY_SEPARATOR.'template-'.str_repeat('b', 32);
        mkdir($this->candidate, 0700);
        foreach ([$this->root => 'old', $this->candidate => 'new'] as $directory => $version) {
            mkdir($directory.'/public');
            mkdir($directory.'/storage');
            file_put_contents($directory.'/public/index.php', $version);
            file_put_contents($directory.'/storage/state', $version);
            file_put_contents($directory.'/.env', $version);
            file_put_contents($directory.'/'.$version.'-only.txt', $version);
        }
        file_put_contents($private.'/upload.zip', 'private upload remains here');
    }

    public function test_swap_and_restore_preserve_root_and_work_without_parent_write_permissions(): void
    {
        $inode = fileinode($this->root);
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod(dirname($this->root), 0555);
            if (posix_geteuid() !== 0) {
                $this->assertFalse(is_writable(dirname($this->root)));
            }
        }
        $files = new PreviewFileSwap;
        $files->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
        $this->assertSame('new', file_get_contents($this->root.'/public/index.php'));
        $this->assertSame('old', file_get_contents($this->backup.'/public/index.php'));
        $this->assertFileDoesNotExist($this->root.'/old-only.txt');
        $failed = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private'.DIRECTORY_SEPARATOR.'failed-'.str_repeat('d', 32);
        $files->exchange($this->root, $this->backup, $failed, str_repeat('c', 40));
        $this->assertOriginalFiles();
        $this->assertSame('new', file_get_contents($failed.'/public/index.php'));
        clearstatcache();
        $this->assertSame($inode, fileinode($this->root));
    }

    public function test_update_swap_keeps_the_existing_environment_and_storage(): void
    {
        $files = new PreviewFileSwap;
        $files->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40), retainRuntime: true);

        $this->assertSame('new', file_get_contents($this->root.'/public/index.php'));
        $this->assertSame('old', file_get_contents($this->root.'/.env'));
        $this->assertSame('old', file_get_contents($this->root.'/storage/state'));
        $this->assertFileDoesNotExist($this->backup.'/storage/state');

        $failed = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private'.DIRECTORY_SEPARATOR.'failed-'.str_repeat('d', 32);
        $files->exchange($this->root, $this->backup, $failed, str_repeat('c', 40), retainRuntime: true);
        $this->assertOriginalFiles();
    }

    #[DataProvider('interruptionPoints')]
    public function test_interrupted_moves_recover_without_overwriting_or_losing_any_files(int $at, bool $after): void
    {
        $files = $this->interruptingSwap($at, $after);
        try {
            $files->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
            $this->fail('Interruption was not exercised.');
        } catch (RuntimeException) {
            (new PreviewFileSwap)->recover($this->root, str_repeat('c', 40));
            (new PreviewFileSwap)->recover($this->root, str_repeat('c', 40));
            $this->assertOriginalFiles();
            $this->assertSame('new', file_get_contents($this->candidate.'/public/index.php'));
            $this->assertSame('new', file_get_contents($this->candidate.'/.env'));
        }
    }

    public static function interruptionPoints(): array
    {
        $points = [];
        foreach (range(1, 8) as $at) {
            $points[] = [$at, false];
            $points[] = [$at, true];
        }

        return $points;
    }

    #[DataProvider('interruptionPoints')]
    public function test_interrupted_template_restore_recovers_the_installed_preview(int $at, bool $after): void
    {
        (new PreviewFileSwap)->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
        $failed = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private'.DIRECTORY_SEPARATOR.'failed-'.str_repeat('d', 32);
        try {
            $this->interruptingSwap($at, $after)->exchange($this->root, $this->backup, $failed, str_repeat('c', 40));
            $this->fail('Restore interruption was not exercised.');
        } catch (RuntimeException) {
            (new PreviewFileSwap)->recover($this->root, str_repeat('c', 40));
            $this->assertSame('new', file_get_contents($this->root.'/public/index.php'));
            $this->assertSame('new', file_get_contents($this->root.'/.env'));
            $this->assertSame('old', file_get_contents($this->backup.'/public/index.php'));
            $this->assertSame('old', file_get_contents($this->backup.'/.env'));
            $this->assertFileDoesNotExist($this->root.'/.stocks-preview-private/swap.json');
        }
    }

    public function test_webroot_is_absent_while_other_entries_are_moved(): void
    {
        $files = new class($this->root) extends PreviewFileSwap
        {
            public array $webrootDuringOtherMoves = [];

            public function __construct(private string $root) {}

            protected function move(string $source, string $destination): void
            {
                if (basename($source) !== 'public') {
                    $this->webrootDuringOtherMoves[] = file_exists($this->root.'/public');
                }
                parent::move($source, $destination);
            }
        };
        $files->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
        $failed = $this->root.DIRECTORY_SEPARATOR.'.stocks-preview-private'.DIRECTORY_SEPARATOR.'failed-'.str_repeat('d', 32);
        $files->exchange($this->root, $this->backup, $failed, str_repeat('c', 40));
        $this->assertCount(12, $files->webrootDuringOtherMoves);
        $this->assertSame([false], array_values(array_unique($files->webrootDuringOtherMoves)));
    }

    public function test_conflicting_recovery_path_is_preserved_and_pending_journal_blocks_new_exchange(): void
    {
        try {
            $this->interruptingSwap(1, true)->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
        } catch (RuntimeException) {
            mkdir($this->root.'/public');
            file_put_contents($this->root.'/public/unrelated', 'keep');
        }
        try {
            (new PreviewFileSwap)->recover($this->root, str_repeat('c', 40));
            $this->fail('Conflicting recovery was accepted.');
        } catch (RuntimeException) {
            $this->assertSame('keep', file_get_contents($this->root.'/public/unrelated'));
            $this->assertSame('old', file_get_contents($this->backup.'/public/index.php'));
            $this->assertFileExists($this->root.'/.stocks-preview-private/swap.json');
        }
        $this->expectException(RuntimeException::class);
        (new PreviewFileSwap)->assertNoPendingSwap($this->root);
    }

    public function test_dangling_root_symlink_is_rejected_before_any_file_moves(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows symlink creation requires separate privileges; Linux CI covers this boundary.');
        }
        symlink($this->directory.'/missing-target', $this->root.'/dangling');
        try {
            (new PreviewFileSwap)->exchange($this->root, $this->candidate, $this->backup, str_repeat('c', 40));
            $this->fail('A dangling root symlink was accepted.');
        } catch (RuntimeException) {
            $this->assertOriginalFiles();
            $this->assertTrue(is_link($this->root.'/dangling'));
        }
    }

    private function interruptingSwap(int $at, bool $after): PreviewFileSwap
    {
        return new class($at, $after) extends PreviewFileSwap
        {
            private int $moves = 0;

            private bool $interrupted = false;

            public function __construct(private int $at, private bool $after) {}

            protected function move(string $source, string $destination): void
            {
                if ($this->interrupted) {
                    throw new RuntimeException('Simulated unavailable filesystem during rollback.');
                }
                $this->moves++;
                if ($this->moves === $this->at && ! $this->after) {
                    $this->interrupted = true;
                    throw new RuntimeException('Simulated interruption before rename.');
                }
                parent::move($source, $destination);
                if ($this->moves === $this->at && $this->after) {
                    $this->interrupted = true;
                    throw new RuntimeException('Simulated interruption after rename.');
                }
            }
        };
    }

    private function assertOriginalFiles(): void
    {
        $this->assertSame('old', file_get_contents($this->root.'/public/index.php'));
        $this->assertSame('old', file_get_contents($this->root.'/.env'));
        $this->assertSame('old', file_get_contents($this->root.'/storage/state'));
        $this->assertFileExists($this->root.'/old-only.txt');
        $this->assertFileDoesNotExist($this->root.'/new-only.txt');
        $this->assertSame('private upload remains here', file_get_contents($this->root.'/.stocks-preview-private/upload.zip'));
        $this->assertFileDoesNotExist($this->root.'/.stocks-preview-private/swap.json');
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod(dirname($this->root), 0755);
        }
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }
}
