<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PreviewDataRefreshWorkflowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stocks-refresh-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_refresh_transfers_authenticated_encrypted_snapshot_then_imports_and_finishes(): void
    {
        $process = $this->runWorkflow();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('Preview data refreshed from read-only production source', $process->getOutput());
        $this->assertSame([
            'source-commit', 'preview-directory', 'toolkit-to-preview', 'prepare', 'request-from-preview',
            'source-directory', 'toolkit-to-source', 'request-to-source', 'export', 'snapshot-from-source',
            'snapshot-to-preview', 'inspect', 'import', 'finish', 'cleanup',
        ], file($this->directory.'/operations', FILE_IGNORE_NEW_LINES));
        $this->assertSame([], glob($this->directory.'/refresh-*/*.snapshot'));
    }

    public function test_failed_import_preserves_transfer_and_reports_recovery_command(): void
    {
        $process = $this->runWorkflow(failImport: true);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('restore', $process->getErrorOutput());
        $this->assertStringContainsString('finish', $process->getErrorOutput());
        $operations = file($this->directory.'/operations', FILE_IGNORE_NEW_LINES);
        $this->assertContains('import', $operations);
        $this->assertNotContains('finish', $operations);
        $this->assertNotContains('cleanup', $operations);
        $this->assertCount(1, glob($this->directory.'/refresh-*/original.snapshot'));
    }

    public function test_ssh_and_scp_clients_resolve_when_windows_open_ssh_is_outside_path(): void
    {
        $shell = (new ExecutableFinder)->find(getenv('STOCKS_TEST_SHELL') ?: 'pwsh');
        if ($shell === null) {
            $this->markTestSkipped('PowerShell is required for SSH client resolution tests.');
        }
        $script = <<<'POWERSHELL'
. $env:STOCKS_TEST_PREVIEW_HELPER
. $env:STOCKS_TEST_DEPLOY_HELPER
$paths = @((Get-StocksPreviewClient -Name ssh), (Get-StocksPreviewClient -Name scp), (Get-StocksLiveSshExecutable))
foreach ($path in $paths) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing client: $path" }
}
Write-Output ($paths.Count)
POWERSHELL;
        $process = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
            $this->directory, [
                'STOCKS_TEST_PREVIEW_HELPER' => dirname(__DIR__, 2).'/scripts/git_preview_helpers.ps1',
                'STOCKS_TEST_DEPLOY_HELPER' => dirname(__DIR__, 2).'/scripts/git_deploy_helpers.ps1',
            ], timeout: 30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame('3', trim($process->getOutput()));
    }

    private function runWorkflow(bool $failImport = false): Process
    {
        $shell = (new ExecutableFinder)->find(getenv('STOCKS_TEST_SHELL') ?: 'pwsh');
        if ($shell === null) {
            $this->markTestSkipped('PowerShell is required for preview transfer tests.');
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
. $env:STOCKS_TEST_HELPER
function Add-Operation { param([string]$Name) [IO.File]::AppendAllText($env:STOCKS_TEST_DIRECTORY + '/operations', $Name + "`n") }
function Invoke-StocksPreviewSsh {
    param([string]$Destination, [string]$Command)
    if ($Command -match 'rev-parse HEAD') { Add-Operation 'source-commit'; return ('b' * 40) }
    if ($Command -match 'mkdir -m 700' -and $Destination -like 'sftp_for*') { Add-Operation 'preview-directory'; return }
    if ($Command -match " prepare '[^']+' '(?<directory>[^']+)' ") {
        Add-Operation 'prepare'
        return (@{ request = "$($Matches.directory)/request.json"; request_sha256 = $env:STOCKS_TEST_REQUEST_SHA; context = @{ source_commit = ('b' * 40); target_commit = ('a' * 40) } } | ConvertTo-Json -Depth 4 -Compress)
    }
    if ($Command -match 'mkdir -m 700' -and $Destination -like 'sftp_gkstocks*') { Add-Operation 'source-directory'; return }
    if ($Command -match " export '[^']+' '(?<directory>[^']+)' ") {
        Add-Operation 'export'
        return (@{ file = "$($Matches.directory)/original.snapshot"; sha256 = $env:STOCKS_TEST_SNAPSHOT_SHA } | ConvertTo-Json -Compress)
    }
    if ($Command -match ' inspect ') { Add-Operation 'inspect'; return 'inspected' }
    if ($Command -match ' import ') {
        Add-Operation 'import'
        if ($env:STOCKS_TEST_FAIL_IMPORT -eq '1') { throw 'simulated import failure' }
        return 'imported'
    }
    if ($Command -match ' finish ') { Add-Operation 'finish'; return 'finished' }
    if ($Command -match '^rm -f --') { Add-Operation 'cleanup'; return }
    throw "Unexpected SSH command: $Command"
}
function Invoke-StocksPreviewScp {
    param([string[]]$Sources, [string]$Destination)
    if ($Destination -match 'request\.json$' -and $Sources[0] -like 'sftp_for*') {
        Add-Operation 'request-from-preview'
        [IO.File]::WriteAllText($Destination, 'request fixture')
        return
    }
    if ($Destination -match 'original\.snapshot$' -and $Sources[0] -like 'sftp_gkstocks*') {
        Add-Operation 'snapshot-from-source'
        [IO.File]::WriteAllText($Destination, 'encrypted snapshot fixture')
        return
    }
    if ($Destination -like 'sftp_for*' -and $Sources.Count -gt 1) { Add-Operation 'toolkit-to-preview'; return }
    if ($Destination -like 'sftp_gkstocks*' -and $Sources.Count -gt 1) { Add-Operation 'toolkit-to-source'; return }
    if ($Destination -like 'sftp_gkstocks*' -and $Sources[0] -match 'request\.json$') { Add-Operation 'request-to-source'; return }
    if ($Destination -like 'sftp_for*' -and $Sources[0] -match 'original\.snapshot$') { Add-Operation 'snapshot-to-preview'; return }
    throw "Unexpected SCP transfer: $($Sources -join ',') -> $Destination"
}
$bundle = [pscustomobject]@{ Directory = $env:STOCKS_TEST_DIRECTORY; Commit = ('a' * 40) }
$target = [pscustomobject]@{ serverAddress = '165.227.156.99'; sshUser = 'sftp_for_gkstocks_feature'; canonicalTargetRoot = '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html'; targetOwnerUid = '1013' }
try { Invoke-StocksPreviewDataRefresh -Bundle $bundle -Target $target }
catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
POWERSHELL;
        $process = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
            $this->directory, [
                'STOCKS_TEST_HELPER' => dirname(__DIR__, 2).'/scripts/git_preview_helpers.ps1',
                'STOCKS_TEST_DIRECTORY' => $this->directory,
                'STOCKS_TEST_REQUEST_SHA' => hash('sha256', 'request fixture'),
                'STOCKS_TEST_SNAPSHOT_SHA' => hash('sha256', 'encrypted snapshot fixture'),
                'STOCKS_TEST_FAIL_IMPORT' => $failImport ? '1' : '0',
            ], timeout: 30);
        $process->run();

        return $process;
    }
}
