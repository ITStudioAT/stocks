<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class GitBranchWorkflowTest extends TestCase
{
    private string $directory;

    private string $shell;

    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $finder = new ExecutableFinder;
        $shell = $finder->find(getenv('STOCKS_TEST_SHELL') ?: 'pwsh');
        if ($shell === null) {
            $this->markTestSkipped('PowerShell is required for executable Git workflow tests.');
        }
        $this->shell = $shell;
        $this->directory = sys_get_temp_dir().'/stocks-branch-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/gitconfig', '');
        $this->environment = [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->directory.'/gitconfig',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'Workflow Test',
            'GIT_AUTHOR_EMAIL' => 'workflow@example.test',
            'GIT_COMMITTER_NAME' => 'Workflow Test',
            'GIT_COMMITTER_EMAIL' => 'workflow@example.test',
            'STOCKS_TEST_GIT' => $finder->find('git'),
            'STOCKS_TEST_FETCH_URL' => 'https://github.com/ITStudioAT/stocks.git',
            'STOCKS_TEST_PUSH_URL' => 'git@github.com:ITStudioAT/stocks.git',
            'APP_ENV' => 'local',
        ];
        $this->git($this->directory, 'init', '--bare', '--initial-branch=main', 'origin.git');
        $this->git($this->directory, 'clone', 'origin.git', 'pc');
        mkdir($this->directory.'/pc/scripts');
        foreach (['git_branch_helpers.ps1', 'git_workflow.ps1', 'install_powershell_helpers.ps1'] as $script) {
            copy(dirname(__DIR__, 2).'/scripts/'.$script, $this->directory.'/pc/scripts/'.$script);
        }
        file_put_contents($this->directory.'/pc/.gitignore', ".env\n");
        file_put_contents($this->directory.'/pc/example.txt', "initial\n");
        $this->git($this->directory.'/pc', 'add', '-A');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Initialize fixture');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        foreach (['other', 'laptop'] as $device) {
            $this->git($this->directory, 'clone', 'origin.git', $device);
        }
    }

    public function test_three_devices_share_features_without_changing_main_or_tags(): void
    {
        $main = $this->git($this->directory.'/pc', 'rev-parse', 'main');
        $this->succeeds('pc', 'gitstart', 'depot-filter', '-NoPrepare');
        file_put_contents($this->directory.'/pc/filter.txt', 'new filter');
        $this->git($this->directory.'/pc', 'tag', '-a', 'local-only', '-m', 'Local only');
        $this->git($this->directory.'/pc', 'config', 'push.followTags', 'true');
        $this->succeeds('pc', 'gitsave', 'Add depot filter');
        $this->succeeds('other', 'gitwork', 'depot-filter', '-NoPrepare');
        $this->assertSame('new filter', file_get_contents($this->directory.'/other/filter.txt'));
        file_put_contents($this->directory.'/other/filter.txt', 'improved filter');
        $this->succeeds('other', 'gitsave', 'Improve depot filter');
        $this->succeeds('laptop', 'gitwork', '-NoPrepare');
        $this->assertSame('improved filter', file_get_contents($this->directory.'/laptop/filter.txt'));
        $this->succeeds('pc', 'gitwork', 'depot-filter', '-NoPrepare');
        $this->succeeds('pc', 'gitmain', '-NoPrepare');
        $this->assertSame($main, $this->git($this->directory.'/pc', 'rev-parse', 'HEAD'));
        $this->assertSame('', $this->git($this->directory.'/origin.git', 'tag', '--list'));
    }

    public function test_parallel_features_require_an_explicit_choice(): void
    {
        foreach (['first', 'second'] as $feature) {
            $this->succeeds('pc', 'gitstart', $feature, '-NoPrepare');
            $this->succeeds('pc', 'gitsave', 'Share empty feature');
        }
        $this->fails('other', 'Choose a feature', 'gitwork', '-NoPrepare');
        $this->succeeds('other', 'gitwork', 'second', '-NoPrepare');
        $this->assertSame('codex/second', $this->git($this->directory.'/other', 'branch', '--show-current'));
    }

    public function test_switches_preserve_unsaved_changes_and_unpublished_commits(): void
    {
        $this->succeeds('pc', 'gitstart', 'local-work', '-NoPrepare');
        file_put_contents($this->directory.'/pc/unsaved.txt', 'keep me');
        $this->fails('pc', 'Unsaved changes', 'gitmain', '-NoPrepare');
        $this->assertSame('keep me', file_get_contents($this->directory.'/pc/unsaved.txt'));
        $this->git($this->directory.'/pc', 'add', '-A');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Keep local work');
        $this->fails('pc', 'unpublished or divergent', 'gitmain', '-NoPrepare');
        $this->assertSame('codex/local-work', $this->git($this->directory.'/pc', 'branch', '--show-current'));
    }

    public function test_a_stale_save_does_not_commit_or_discard_changes(): void
    {
        $this->succeeds('pc', 'gitstart', 'shared-work', '-NoPrepare');
        $this->succeeds('pc', 'gitsave', 'Share feature');
        $this->succeeds('other', 'gitwork', 'shared-work', '-NoPrepare');
        file_put_contents($this->directory.'/other/remote.txt', 'other device');
        $this->succeeds('other', 'gitsave', 'Save remote change');
        $before = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        file_put_contents($this->directory.'/pc/local.txt', 'local change');
        $this->fails('pc', 'Nothing was committed', 'gitsave', 'Stale save');
        $this->assertSame($before, $this->git($this->directory.'/pc', 'rev-parse', 'HEAD'));
        $this->assertSame('local change', file_get_contents($this->directory.'/pc/local.txt'));
        $this->assertSame('?? local.txt', $this->git($this->directory.'/pc', 'status', '--porcelain'));
    }

    public function test_a_rejected_push_keeps_the_commit_available_for_retry(): void
    {
        $this->succeeds('pc', 'gitstart', 'retry-save', '-NoPrepare');
        file_put_contents($this->directory.'/origin.git/hooks/pre-receive', "#!/bin/sh\nexit 1\n");
        chmod($this->directory.'/origin.git/hooks/pre-receive', 0755);
        file_put_contents($this->directory.'/pc/retry.txt', 'preserve this commit');
        $before = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->fails('pc', 'GitHub save failed', 'gitsave', 'Save for retry');
        $this->assertNotSame($before, $this->git($this->directory.'/pc', 'rev-parse', 'HEAD'));
        $this->assertSame('', $this->git($this->directory.'/pc', 'status', '--porcelain'));
        $this->assertSame('preserve this commit', $this->git($this->directory.'/pc', 'show', 'HEAD:retry.txt'));
    }

    public function test_a_target_branch_with_unpublished_commits_is_not_overwritten(): void
    {
        $this->succeeds('pc', 'gitstart', 'saved-feature', '-NoPrepare');
        $this->succeeds('pc', 'gitsave', 'Share feature');
        $this->git($this->directory.'/pc', 'switch', 'main');
        file_put_contents($this->directory.'/pc/main-local.txt', 'unpublished main');
        $this->git($this->directory.'/pc', 'add', '-A');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Unpublished main work');
        $main = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->git($this->directory.'/pc', 'switch', 'codex/saved-feature');
        $this->fails('pc', 'No branch was switched', 'gitmain', '-NoPrepare');
        $this->assertSame('codex/saved-feature', $this->git($this->directory.'/pc', 'branch', '--show-current'));
        $this->assertSame($main, $this->git($this->directory.'/pc', 'rev-parse', 'main'));
    }

    public function test_update_merges_main_locally_and_preserves_conflicts(): void
    {
        $this->succeeds('pc', 'gitstart', 'update-main', '-NoPrepare');
        file_put_contents($this->directory.'/pc/example.txt', "feature\n");
        $this->succeeds('pc', 'gitsave', 'Edit feature');
        $saved = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        file_put_contents($this->directory.'/other/main-only.txt', 'new main file');
        $this->git($this->directory.'/other', 'add', '-A');
        $this->git($this->directory.'/other', 'commit', '-m', 'Advance main');
        $this->git($this->directory.'/other', 'push', 'origin', 'main');
        $this->succeeds('pc', 'gitupdate', '-NoPrepare');
        $this->assertFileExists($this->directory.'/pc/main-only.txt');
        $this->assertSame($saved, $this->git($this->directory.'/origin.git', 'rev-parse', 'codex/update-main'));
        $this->succeeds('pc', 'gitsave', 'Share main integration');
        file_put_contents($this->directory.'/other/example.txt', "conflicting main\n");
        $this->git($this->directory.'/other', 'add', '-A');
        $this->git($this->directory.'/other', 'commit', '-m', 'Conflict on main');
        $this->git($this->directory.'/other', 'push', 'origin', 'main');
        $this->fails('pc', 'Git failed', 'gitupdate', '-NoPrepare');
        $this->assertStringContainsString('<<<<<<<', file_get_contents($this->directory.'/pc/example.txt'));
        $this->fails('pc', 'unfinished', 'gitsave', 'Do not save conflict');
    }

    public function test_main_detached_head_invalid_arguments_and_wrong_remotes_are_rejected(): void
    {
        $this->fails('pc', 'requires a codex/', 'gitsave', 'Do not release main');
        $this->fails('pc', 'feature name', 'gitstart', '--force', '-NoPrepare');
        $this->fails('pc', 'Usage: gitsave', 'gitsave', 'message', '1.2.3');
        $this->git($this->directory.'/pc', 'switch', '--detach');
        $this->fails('pc', 'Detached HEAD', 'gitmain', '-NoPrepare');
        $this->environment['STOCKS_TEST_PUSH_URL'] = 'https://github.com/ITStudioAT/schooltool.git';
        $this->fails('other', 'only trusts ITStudioAT/stocks', 'gitstart', 'wrong-remote', '-NoPrepare');
    }

    public function test_preparation_requires_local_environment_and_never_runs_database_updates(): void
    {
        $this->fails('pc', '.env is missing', 'gitprepare');
        file_put_contents($this->directory.'/pc/.env', "APP_ENV=production\n");
        $this->fails('pc', 'APP_ENV=local', 'gitprepare');
        file_put_contents($this->directory.'/pc/.env', "APP_ENV=local\n");
        $this->environment['STOCKS_TEST_PREPARE'] = '1';
        $result = $this->workflow('pc', 'gitprepare');
        $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
        $this->assertStringContainsString('scripts/update.php --target=local --prepare', $result->getOutput());
        $this->assertStringContainsString('artisan config:clear', $result->getOutput());
        $this->assertStringContainsString('npm run build', $result->getOutput());
        $this->assertStringNotContainsString('artisan migrate', $result->getOutput());
        $this->assertStringNotContainsString('artisan app:update', $result->getOutput());
        $this->environment['STOCKS_TEST_PREPARE'] = 'fail';
        $this->fails('pc', 'Dependency preparation failed', 'gitstart', 'prepare-failure');
        $this->assertSame('codex/prepare-failure', $this->git($this->directory.'/pc', 'branch', '--show-current'));
        $this->assertSame('', $this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/*'));
    }

    public function test_installer_preserves_profiles_and_dispatches_both_projects(): void
    {
        $profile = $this->directory.'/profile.ps1';
        file_put_contents($profile, "function PersonalHelper { 'keep me' }\n");
        $this->environment['STOCKS_TEST_PROFILE'] = $profile;
        $install = '& ./scripts/install_powershell_helpers.ps1 -ProfilePaths @($env:STOCKS_TEST_PROFILE)';
        $first = $this->powershell($this->directory.'/pc', $install);
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $contents = file_get_contents($profile);
        $second = $this->powershell($this->directory.'/pc', $install);
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertSame($contents, file_get_contents($profile));
        $this->assertStringContainsString('function PersonalHelper', $contents);
        $this->assertCount(1, glob($profile.'.stocks-backup-*'));
        file_put_contents($this->directory.'/pc/scripts/git_workflow.ps1', <<<'POWERSHELL'
param([string]$Command, [string[]]$CommandArguments)
Write-Output "DISPATCH $Command $($CommandArguments -join '|')"
Write-Output "ROOT $((Get-Location).Path)"
POWERSHELL);
        foreach (['stocks', 'schooltool'] as $project) {
            $this->environment['STOCKS_TEST_FETCH_URL'] = "https://github.com/ITStudioAT/$project.git";
            $this->environment['STOCKS_TEST_PUSH_URL'] = "git@github.com:ITStudioAT/$project.git";
            $result = $this->powershell($this->directory.'/pc/scripts', <<<'POWERSHELL'
. $env:STOCKS_TEST_PROFILE
gitsave 'Message with spaces'
gitwork 'depot-filter'
Write-Output "RETURN $((Get-Location).Path)"
POWERSHELL);
            $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
            $this->assertStringContainsString('DISPATCH gitsave Message with spaces', $result->getOutput());
            $this->assertStringContainsString('DISPATCH gitwork depot-filter', $result->getOutput());
            $this->assertStringContainsString('RETURN '.realpath($this->directory.'/pc/scripts'), $result->getOutput());
        }
        $this->environment['STOCKS_TEST_PUSH_URL'] = 'https://github.com/ITStudioAT/stocks.git';
        $result = $this->powershell($this->directory.'/pc', '. $env:STOCKS_TEST_PROFILE; gitmain');
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('matching trusted', $result->getErrorOutput());
    }

    private function succeeds(string $device, string ...$arguments): void
    {
        $result = $this->workflow($device, ...$arguments);
        $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
    }

    private function fails(string $device, string $message, string ...$arguments): void
    {
        $result = $this->workflow($device, ...$arguments);
        $this->assertFalse($result->isSuccessful(), $result->getOutput());
        $this->assertStringContainsString($message, $result->getErrorOutput().$result->getOutput());
    }

    private function workflow(string $device, string ...$arguments): Process
    {
        $this->environment['STOCKS_TEST_COMMAND'] = array_shift($arguments);
        $this->environment['STOCKS_TEST_ARGUMENTS'] = json_encode($arguments, JSON_THROW_ON_ERROR);

        return $this->powershell($this->directory.'/'.$device, <<<'POWERSHELL'
[string[]]$commandArguments = ConvertFrom-Json -InputObject $env:STOCKS_TEST_ARGUMENTS
& ./scripts/git_workflow.ps1 -Command $env:STOCKS_TEST_COMMAND -CommandArguments $commandArguments
POWERSHELL);
    }

    private function powershell(string $directory, string $command): Process
    {
        $bootstrap = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
function git {
    if ($args -contains 'get-url') {
        $global:LASTEXITCODE = 0
        if ($args -contains '--push') { $env:STOCKS_TEST_PUSH_URL }
        else { $env:STOCKS_TEST_FETCH_URL }
        return
    }
    & $env:STOCKS_TEST_GIT @args
}
if ($env:STOCKS_TEST_PREPARE) {
    function php {
        Write-Output "php $($args -join ' ')"
        $global:LASTEXITCODE = if ($env:STOCKS_TEST_PREPARE -eq 'fail') { 1 } else { 0 }
    }
    function npm.cmd { Write-Output "npm $($args -join ' ')"; $global:LASTEXITCODE = 0 }
    function npm { Write-Output "npm $($args -join ' ')"; $global:LASTEXITCODE = 0 }
}
POWERSHELL;
        $script = $bootstrap."\ntry {\n".$command."\n".'} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }';
        $process = new Process([$this->shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script], $directory, $this->environment, timeout: 60);
        $process->run();

        return $process;
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, $this->environment, timeout: 30);
        $process->mustRun();

        return trim($process->getOutput());
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with(basename($this->directory), 'stocks-branch-test-')) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }
}
