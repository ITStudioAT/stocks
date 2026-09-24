<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PreviewSnapshotUploadTest extends TestCase
{
    private string $directory;

    private string $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $shell = (new ExecutableFinder)->find('pwsh');
        if ($shell === null) {
            $this->markTestSkipped('PowerShell is required for the upload helper test.');
        }
        $this->shell = $shell;
        $this->directory = sys_get_temp_dir().'/stocks-upload-test-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        foreach (['PreviewOriginalSchema', 'PreviewOriginalPolicy', 'PreviewSnapshotPolicy', 'PreviewSnapshotArchive', 'PreviewSnapshotStream', 'PreviewSnapshotDatabase', 'PreviewSnapshotTransfer', 'PreviewReleaseBundle'] as $service) {
            file_put_contents($this->directory.'/'.$service.'.php', '<?php // fixture');
        }
        file_put_contents($this->directory.'/preview-snapshot.php', '<?php // fixture');
        file_put_contents($this->directory.'/original.snapshot', 'encrypted fixture');
        file_put_contents($this->directory.'/incoming-recipient.key', 'fixture key');
        $context = ['source_app_id' => '6468818', 'target_app_id' => '6690486', 'source_commit' => str_repeat('a', 40), 'target_commit' => '75e531e6b022a09c424d6c73db4bff6923493b53', 'nonce' => str_repeat('b', 64)];
        file_put_contents($this->directory.'/incoming-request.json', json_encode(['context' => $context]));
        file_put_contents($this->directory.'/export-receipt.json', json_encode(['context' => $context, 'sha256' => hash_file('sha256', $this->directory.'/original.snapshot')]));
        $this->manifest();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_verification_accepts_complete_package_without_attempting_ssh(): void
    {
        $process = $this->verify();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('All 13 transfer files', $process->getOutput());
    }

    public function test_changed_payload_and_untrusted_manifest_are_rejected(): void
    {
        file_put_contents($this->directory.'/original.snapshot', 'changed');
        $process = $this->verify();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Transfer file verification failed', $process->getErrorOutput());
        $process = $this->verify(str_repeat('0', 64));
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('manifest authentication failed', $process->getErrorOutput());
    }

    public function test_retries_of_the_same_snapshot_choose_distinct_private_directories(): void
    {
        $destinations = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $process = $this->verify();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame(1, preg_match('~Planned private upload directory: (/home/1486907\\.cloudwaysapps\\.com/hbnucgvzmy/public_html/\\.stocks-preview-private/original-'.str_repeat('b', 64).'-[a-f0-9]{32})~', $process->getOutput(), $matches));
            $destinations[] = $matches[1];
        }
        $this->assertNotSame($destinations[0], $destinations[1]);
    }

    public function test_even_authenticated_manifest_cannot_include_extra_or_traversal_files(): void
    {
        foreach (['../outside.php', 'unapproved.php', 'original.snapshot'] as $name) {
            $this->manifest();
            file_put_contents($this->directory.'/transfer-files.sha256', str_repeat('a', 64).'  '.$name."\n", FILE_APPEND);
            $this->assertNotSame(0, $this->verify()->getExitCode());
        }
    }

    public function test_wrong_target_is_rejected_before_any_connection(): void
    {
        $path = $this->directory.'/incoming-request.json';
        $request = json_decode(file_get_contents($path), true);
        $request['context']['target_app_id'] = '6468818';
        file_put_contents($path, json_encode($request));
        $this->manifest();
        $process = $this->verify();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('identity mismatch', $process->getErrorOutput());
    }

    public function test_remote_preparation_stops_before_mkdir_for_pending_maintenance_or_deployment(): void
    {
        $bash = PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Git/bin/bash.exe' : (new ExecutableFinder)->find('bash');
        if ($bash === null || ! is_file($bash)) {
            $this->markTestSkipped('Bash is required to exercise the remote preparation command.');
        }
        $plan = new Process([$this->shell, '-NoProfile', '-Command', <<<'POWERSHELL'
            . $env:UPLOAD_HELPER -BundleDirectory $env:UPLOAD_FIXTURE -ExpectedManifestSha256 $env:UPLOAD_MANIFEST -VerifyOnly | Out-Null
            $tree = [Management.Automation.Language.Parser]::ParseFile($env:UPLOAD_HELPER, [ref]$null, [ref]$null)
            $assignment = $tree.Find({ param($node) $node -is [Management.Automation.Language.AssignmentStatementAst] -and $node.Left.Extent.Text -eq '$prepare' }, $true)
            . ([scriptblock]::Create($assignment.Extent.Text))
            Write-Output $prepare
            POWERSHELL,
        ], env: [
            'UPLOAD_HELPER' => dirname(__DIR__, 2).'/scripts/preview-snapshot-upload.ps1',
            'UPLOAD_FIXTURE' => $this->directory,
            'UPLOAD_MANIFEST' => hash_file('sha256', $this->directory.'/transfer-files.sha256'),
        ]);
        $plan->mustRun();
        $mocks = <<<'BASH'
            id() { echo 1013; }
            realpath() { echo /home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html; }
            stat() { echo 1013:700; }
            mkdir() { echo 'CREATED_NEW_DIRECTORY'; }
            test() {
                if { [ "$1" = '-e' ] || [ "$1" = '-L' ]; } && [ -n "$PENDING_PATH" ] && [ "$2" = "$PENDING_PATH" ]; then
                    return 0
                fi
                builtin test "$@"
            }
            BASH;
        $root = '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html';
        foreach (['', $root.'/storage/framework/down', $root.'/.stocks-preview-private/swap.json'] as $pending) {
            $process = new Process([$bash, '-c', $mocks."\n".trim($plan->getOutput())], env: ['PENDING_PATH' => $pending]);
            $process->run();
            if ($pending === '') {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringContainsString('CREATED_NEW_DIRECTORY', $process->getOutput());
            } else {
                $this->assertSame(20, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringNotContainsString('CREATED_NEW_DIRECTORY', $process->getOutput());
                $this->assertStringContainsString('Preserve the earlier transfer directory', $process->getErrorOutput());
            }
        }
    }

    private function manifest(): void
    {
        $lines = [];
        foreach (glob($this->directory.'/*') as $file) {
            if (basename($file) !== 'transfer-files.sha256') {
                $lines[] = hash_file('sha256', $file).'  '.basename($file);
            }
        }
        file_put_contents($this->directory.'/transfer-files.sha256', implode("\n", $lines)."\n");
    }

    private function verify(?string $digest = null): Process
    {
        $process = new Process([$this->shell, '-NoProfile', '-File', dirname(__DIR__, 2).'/scripts/preview-snapshot-upload.ps1', '-BundleDirectory', $this->directory, '-ExpectedManifestSha256', $digest ?? hash_file('sha256', $this->directory.'/transfer-files.sha256'), '-VerifyOnly']);
        $process->run();

        return $process;
    }
}
