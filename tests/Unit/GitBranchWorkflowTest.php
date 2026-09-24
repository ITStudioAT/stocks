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
        foreach (['git_branch_helpers.ps1', 'git_workflow.ps1', 'git_preview_helpers.ps1', 'git_deploy_helpers.ps1', 'install_powershell_helpers.ps1', 'stocks_preview_target.json', 'check-encoding.php'] as $script) {
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
        $this->assertSame($main, $this->git($this->directory.'/origin.git', 'rev-parse', 'codex/depot-filter'));
        $reservation = $this->git($this->directory.'/origin.git', 'rev-parse', 'codex/features/depot-filter');
        $metadata = json_decode($this->git($this->directory.'/origin.git', 'show', $reservation.':feature.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('codex/depot-filter', $metadata['branch']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $metadata['id']);
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

    public function test_preview_requires_explicit_feature_and_online_mode_for_data_refresh(): void
    {
        $before = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->fails('pc', 'requires a codex/', 'gitpreview', 'prepare');
        $this->succeeds('pc', 'gitstart', 'preview-work', '-NoPrepare');
        $this->succeeds('pc', 'gitsave', 'Share preview work');
        $this->fails('pc', 'differs from the checkout', 'gitpreview', '-Feature', 'wrong-work');
        $this->fails('pc', 'RefreshData requires an online preview deployment', 'gitpreview', 'prepare', '-Feature', 'preview-work', '-RefreshData');
        $this->assertSame('', $this->git($this->directory.'/pc', 'status', '--porcelain'));
        $this->assertSame($before, $this->git($this->directory.'/pc', 'rev-parse', 'origin/main'));
        file_put_contents($this->directory.'/pc/example.txt', 'unsaved');
        $this->fails('pc', 'Unsaved changes exist', 'gitpreview', 'prepare');
    }

    public function test_preview_bundle_refuses_failed_ci_before_installing_dependencies(): void
    {
        $commit = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->environment['STOCKS_TEST_CI'] = json_encode([['status' => 'completed', 'conclusion' => 'failure', 'headSha' => $commit]], JSON_THROW_ON_ERROR);
        $this->fails('pc', 'requires a successful completed GitHub CI run', 'gitpreview', 'prepare', '-Main');
        $this->assertDirectoryDoesNotExist($this->directory.'/pc/.git/stocks-preview');
        $this->assertSame('', $this->git($this->directory.'/pc', 'status', '--porcelain'));
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
        $this->fails('pc', 'feature name', 'gitstart', '--force', '-NoPrepare');
        $this->fails('pc', 'Usage: gitsave', 'gitsave', 'message', '1.2.3', 'extra');
        $this->git($this->directory.'/pc', 'switch', '--detach');
        $this->fails('pc', 'Detached HEAD', 'gitmain', '-NoPrepare');
        $this->environment['STOCKS_TEST_PUSH_URL'] = 'https://github.com/ITStudioAT/schooltool.git';
        $this->fails('other', 'only trusts ITStudioAT/stocks', 'gitstart', 'wrong-remote', '-NoPrepare');
    }

    public function test_existing_feature_is_registered_and_invalid_reservation_blocks_a_save(): void
    {
        $this->git($this->directory.'/pc', 'branch', 'codex/legacy-feature');
        $this->git($this->directory.'/pc', 'push', 'origin', 'codex/legacy-feature');
        $this->succeeds('other', 'gitwork', 'legacy-feature', '-NoPrepare');
        $this->assertNotEmpty($this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/features/legacy-feature'));

        $this->git($this->directory.'/origin.git', 'update-ref', 'refs/heads/codex/features/legacy-feature', $this->git($this->directory.'/origin.git', 'rev-parse', 'main'));
        file_put_contents($this->directory.'/other/unsaved.txt', 'preserve this work');
        $this->fails('other', 'reservation cannot be read', 'gitsave', 'Do not save');
        $this->assertSame('preserve this work', file_get_contents($this->directory.'/other/unsaved.txt'));
    }

    public function test_gitpush_dispatches_safely_and_requires_main(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_helpers.ps1', <<<'POWERSHELL'
function gitpush {
    param([string]$message, [string]$version, [switch]$Full, [switch]$WaitForCI)
    Write-Output "SAFE $message|$version|$Full|$WaitForCI"
}
POWERSHELL);
        $result = $this->workflow('pc', 'gitpush', 'Publish checked source', '1.2.3', '-Full');
        $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
        $this->assertStringContainsString('SAFE Publish checked source|1.2.3|True|False', $result->getOutput());
        $this->fails('pc', 'Usage: gitpush', 'gitpush', 'message', '1.2.3', 'extra');

        unlink($this->directory.'/pc/scripts/git_helpers.ps1');
        $this->succeeds('pc', 'gitstart', 'guarded-push', '-NoPrepare');
        copy(dirname(__DIR__, 2).'/scripts/git_helpers.ps1', $this->directory.'/pc/scripts/git_helpers.ps1');
        $this->fails('pc', 'only publishes the main branch', 'gitpush', 'Do not publish');
    }

    public function test_gitsave_publishes_main_with_optional_version_and_keeps_feature_versions_blocked(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_helpers.ps1', <<<'POWERSHELL'
function gitpush {
    param([string]$message, [string]$version, [switch]$Full, [switch]$WaitForCI)
    Write-Output "RELEASE $message|$version|$Full|$WaitForCI"
}
POWERSHELL);
        $release = $this->workflow('pc', 'gitsave', 'Add dashboard', '1.2.3', '-Full', '-WaitForCI');
        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertStringContainsString('RELEASE Add dashboard|1.2.3|True|True', $release->getOutput());
        unlink($this->directory.'/pc/scripts/git_helpers.ps1');

        $this->succeeds('pc', 'gitstart', 'dashboard', '-NoPrepare');
        $this->fails('pc', 'Feature saves accept only a description', 'gitsave', 'Add dashboard', '1.2.3');
        $this->fails('pc', 'Switch to the clean main branch', 'gitdeploy');
    }

    public function test_gitrelease_squashes_only_the_saved_feature_into_main_after_confirmation(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_helpers.ps1', <<<'POWERSHELL'
function gitpush {
    param([string]$message, [string]$version, [switch]$Full, [switch]$WaitForCI)
    if ($Full -or $WaitForCI) { throw 'The release waited for full checks or CI.' }
    git commit -m $message | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Fixture release commit failed.' }
    git push origin HEAD:main | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Fixture release push failed.' }
    Write-Output "RELEASED $message|$version"
}
POWERSHELL);
        $this->git($this->directory.'/pc', 'add', 'scripts/git_helpers.ps1');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Add fixture publisher');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        $this->succeeds('pc', 'gitstart', 'only-this-feature', '-NoPrepare');
        file_put_contents($this->directory.'/pc/feature.txt', 'released');
        $this->succeeds('pc', 'gitsave', 'Complete feature');

        $this->environment['STOCKS_TEST_PROMPT'] = 'RELEASE';
        $released = $this->workflow('pc', 'gitrelease', 'Add feature', '1.2.3');

        $this->assertTrue($released->isSuccessful(), $released->getOutput().$released->getErrorOutput());
        $this->assertStringContainsString('RELEASED Add feature|1.2.3', $released->getOutput());
        $this->assertSame('main', $this->git($this->directory.'/pc', 'branch', '--show-current'));
        $this->assertSame('released', $this->git($this->directory.'/origin.git', 'show', 'main:feature.txt'));
        $this->assertSame('', $this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/only-this-feature'));
        $this->assertNotEmpty($this->git($this->directory.'/pc', 'for-each-ref', '--format=%(refname)', 'refs/stocks/released'));
    }

    public function test_gitdiscard_deletes_only_the_confirmed_inactive_feature_and_keeps_a_recovery_ref(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_preview_helpers.ps1', <<<'POWERSHELL'
function Invoke-StocksPreviewSsh { param([string]$Destination, [string]$Command) $env:STOCKS_TEST_PREVIEW_MARKER }
POWERSHELL);
        $this->git($this->directory.'/pc', 'add', 'scripts/git_preview_helpers.ps1');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Add preview fixture');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        $main = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->succeeds('pc', 'gitstart', 'discard-this', '-NoPrepare');
        file_put_contents($this->directory.'/pc/discard.txt', 'temporary');
        $this->succeeds('pc', 'gitsave', 'Share temporary feature');
        $this->succeeds('pc', 'gitmain', '-NoPrepare');
        $this->environment['STOCKS_TEST_PREVIEW_MARKER'] = json_encode([
            'format' => 'stocks-preview-instance-v1',
            'state' => 'active',
            'root' => '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html',
            'target_app_id' => '6690486',
            'commit' => $main,
        ], JSON_THROW_ON_ERROR);
        $this->environment['STOCKS_TEST_PROMPT'] = 'DISCARD codex/discard-this';

        $discarded = $this->workflow('pc', 'gitdiscard', 'discard-this');

        $this->assertTrue($discarded->isSuccessful(), $discarded->getOutput().$discarded->getErrorOutput());
        $this->assertSame('', $this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/discard-this'));
        $this->assertSame($main, $this->git($this->directory.'/origin.git', 'rev-parse', 'main'));
        $this->assertNotEmpty($this->git($this->directory.'/pc', 'for-each-ref', '--format=%(refname)', 'refs/stocks/discarded'));
    }

    public function test_gitdeploy_passes_the_exact_main_release_to_cloudways_while_ci_is_running(): void
    {
        $source = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        mkdir($this->directory.'/pc/deployment');
        file_put_contents($this->directory.'/pc/deployment/source-commit', $source."\n");
        file_put_contents($this->directory.'/pc/scripts/git_deploy_helpers.ps1', <<<'POWERSHELL'
function Invoke-StocksLiveSsh { param([string]$Destination, [string]$Command) Write-Output "SSH $Destination $Command" }
POWERSHELL);
        $this->git($this->directory.'/pc', 'add', '-A');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Build fixture release');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        $release = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->environment['STOCKS_TEST_PREPARE'] = '1';
        $this->environment['STOCKS_TEST_PROMPT'] = 'LIVE';
        $this->environment['STOCKS_TEST_CI'] = json_encode([[
            'status' => 'in_progress', 'conclusion' => null, 'headSha' => $release,
        ]], JSON_THROW_ON_ERROR);

        $deployed = $this->workflow('pc', 'gitdeploy');

        $this->assertTrue($deployed->isSuccessful(), $deployed->getOutput().$deployed->getErrorOutput());
        $this->assertStringContainsString('SSH sftp_gkstocks_admin@165.227.156.99', $deployed->getOutput());
        $this->assertStringContainsString('GitHub tests run in the background', $deployed->getOutput());
        $this->assertStringContainsString("STOCKS_EXPECTED_SOURCE_COMMIT={$source} composer pdeploy --no-interaction", $deployed->getOutput());
        $this->assertSame($release, $this->git($this->directory.'/origin.git', 'rev-parse', 'main'));
    }

    public function test_gitpreview_installs_a_saved_update_of_the_same_feature_without_changing_main(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_preview_helpers.ps1', <<<'POWERSHELL'
function New-StocksPreviewBundle {
    param([string]$Branch, [string]$Commit)
    [pscustomobject]@{ Commit = $Commit; Digest = ('a' * 64); Directory = '.'; BundlePath = 'bundle.zip' }
}
function Save-StocksPreviewReceipt { param([object]$Bundle, [string]$Branch, [string]$MainCommit) }
function Invoke-StocksPreviewSsh { param([string]$Destination, [string]$Command) $env:STOCKS_TEST_PREVIEW_MARKER }
function Send-StocksPreviewBundle { param([object]$Bundle, [string]$OldCommit, [switch]$RefreshData) Write-Output "PREVIEW $OldCommit $($Bundle.Commit)" }
POWERSHELL);
        $this->git($this->directory.'/pc', 'add', 'scripts/git_preview_helpers.ps1');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Add preview fixture');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        $main = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->succeeds('pc', 'gitstart', 'preview-flow', '-NoPrepare');
        file_put_contents($this->directory.'/pc/feature.txt', 'first preview');
        $this->succeeds('pc', 'gitsave', 'First preview');
        $old = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        file_put_contents($this->directory.'/pc/feature.txt', 'updated preview');
        $this->succeeds('pc', 'gitsave', 'Update preview');
        $new = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->environment['STOCKS_TEST_PREVIEW_MARKER'] = json_encode([
            'format' => 'stocks-preview-instance-v1',
            'state' => 'active',
            'root' => '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html',
            'source_app_id' => '6468818',
            'target_app_id' => '6690486',
            'commit' => $old,
        ], JSON_THROW_ON_ERROR);

        $deployed = $this->workflow('pc', 'gitpreview', '-Feature', 'preview-flow');

        $this->assertTrue($deployed->isSuccessful(), $deployed->getOutput().$deployed->getErrorOutput());
        $this->assertStringContainsString("PREVIEW {$old} {$new}", $deployed->getOutput());
        $this->assertSame($main, $this->git($this->directory.'/origin.git', 'rev-parse', 'main'));
    }

    public function test_refresh_data_allows_switching_between_saved_features_with_original_data_plan(): void
    {
        file_put_contents($this->directory.'/pc/scripts/git_preview_helpers.ps1', <<<'POWERSHELL'
function New-StocksPreviewBundle {
    param([string]$Branch, [string]$Commit)
    [pscustomobject]@{ Commit = $Commit; Digest = ('a' * 64); Directory = '.'; BundlePath = 'bundle.zip' }
}
function Save-StocksPreviewReceipt { param([object]$Bundle, [string]$Branch, [string]$MainCommit) }
function Invoke-StocksPreviewSsh { param([string]$Destination, [string]$Command) $env:STOCKS_TEST_PREVIEW_MARKER }
function Send-StocksPreviewBundle { param([object]$Bundle, [string]$OldCommit, [switch]$RefreshData) Write-Output "REFRESH $RefreshData $OldCommit $($Bundle.Commit)" }
POWERSHELL);
        $this->git($this->directory.'/pc', 'add', 'scripts/git_preview_helpers.ps1');
        $this->git($this->directory.'/pc', 'commit', '-m', 'Add preview fixture');
        $this->git($this->directory.'/pc', 'push', 'origin', 'main');
        $this->succeeds('pc', 'gitstart', 'first-preview', '-NoPrepare');
        file_put_contents($this->directory.'/pc/first.txt', 'first');
        $this->succeeds('pc', 'gitsave', 'Save first preview');
        $old = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->succeeds('pc', 'gitmain', '-NoPrepare');
        $this->succeeds('pc', 'gitstart', 'second-preview', '-NoPrepare');
        file_put_contents($this->directory.'/pc/second.txt', 'second');
        $this->succeeds('pc', 'gitsave', 'Save second preview');
        $new = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->environment['STOCKS_TEST_PREVIEW_MARKER'] = json_encode([
            'format' => 'stocks-preview-instance-v1', 'state' => 'active',
            'root' => '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html',
            'source_app_id' => '6468818', 'target_app_id' => '6690486', 'commit' => $old,
        ], JSON_THROW_ON_ERROR);

        $this->fails('pc', 'fresh data snapshot is required', 'gitpreview', '-Feature', 'second-preview');
        $deployed = $this->workflow('pc', 'gitpreview', '-Feature', 'second-preview', '-RefreshData');

        $this->assertTrue($deployed->isSuccessful(), $deployed->getOutput().$deployed->getErrorOutput());
        $this->assertStringContainsString("REFRESH True {$old} {$new}", $deployed->getOutput());

        mkdir($this->directory.'/pc/database/migrations', 0777, true);
        file_put_contents($this->directory.'/pc/database/migrations/2026_09_24_000000_preview_change.php', '<?php');
        $this->succeeds('pc', 'gitsave', 'Add preview migration');
        $this->fails('pc', 'contains database migrations', 'gitpreview', '-Feature', 'second-preview', '-RefreshData');
    }

    public function test_version_publication_requires_prepared_notes_and_updates_the_application_version(): void
    {
        copy(dirname(__DIR__, 2).'/scripts/git_helpers.ps1', $this->directory.'/pc/scripts/git_helpers.ps1');
        mkdir($this->directory.'/pc/config');
        file_put_contents($this->directory.'/pc/config/stocks.php', "<?php\nreturn [\n    'version' => '1.0.2',\n];\n");
        file_put_contents($this->directory.'/pc/UPDATES.md', "## 1.0.2\nOld release\n");

        $missingNotes = $this->powershell($this->directory.'/pc', '. ./scripts/git_helpers.ps1; Set-StocksReleaseVersion -Version 1.0.3');
        $this->assertFalse($missingNotes->isSuccessful());
        $this->assertStringContainsString('Add the release notes', $missingNotes->getErrorOutput());
        $this->assertStringContainsString("'version' => '1.0.2'", file_get_contents($this->directory.'/pc/config/stocks.php'));

        file_put_contents($this->directory.'/pc/UPDATES.md', "## 1.0.3\nNew release\n\n## 1.0.2\nOld release\n");
        $updated = $this->powershell($this->directory.'/pc', '. ./scripts/git_helpers.ps1; Set-StocksReleaseVersion -Version 1.0.3');
        $this->assertTrue($updated->isSuccessful(), $updated->getErrorOutput());
        $this->assertStringContainsString("'version' => '1.0.3'", file_get_contents($this->directory.'/pc/config/stocks.php'));
    }

    public function test_preview_resume_reuses_only_the_exact_untampered_bundle_and_ci_commit(): void
    {
        $commit = $this->git($this->directory.'/pc', 'rev-parse', 'HEAD');
        $this->environment['STOCKS_TEST_CI'] = json_encode([[
            'status' => 'completed', 'conclusion' => 'success', 'headSha' => $commit,
        ]], JSON_THROW_ON_ERROR);
        $this->environment['STOCKS_TEST_COMMIT'] = $commit;
        $prepared = $this->powershell($this->directory.'/pc', <<<'POWERSHELL'
. ./scripts/git_branch_helpers.ps1
. ./scripts/git_preview_helpers.ps1
$id = 'a' * 32
$directory = git rev-parse --git-path stocks-preview
$build = Join-Path $directory "build-$id"
New-Item -ItemType Directory -Path $build -Force | Out-Null
$names = @("stocks-preview-$env:STOCKS_TEST_COMMIT.zip", 'PreviewReleaseUpdate.php', 'PreviewReleaseBundle.php', 'PreviewFileSwap.php', 'preview-update.php', 'stocks_preview_target.json') + @(Get-StocksSnapshotToolkitFiles)
foreach ($name in $names) { [IO.File]::WriteAllText((Join-Path $build $name), $name) }
$digest = (Get-FileHash -LiteralPath (Join-Path $build $names[0]) -Algorithm SHA256).Hash.ToLowerInvariant()
$bundle = [pscustomobject]@{ Id = $id; Directory = $build; BundlePath = (Join-Path $build $names[0]); Digest = $digest; Commit = $env:STOCKS_TEST_COMMIT }
Save-StocksPreviewReceipt -Bundle $bundle -Branch main -MainCommit $env:STOCKS_TEST_COMMIT
$resumed = Read-StocksPreviewReceipt -Id $id -Branch main -Commit $env:STOCKS_TEST_COMMIT -MainCommit $env:STOCKS_TEST_COMMIT
Write-Output "RESUMED $($resumed.Id)"
POWERSHELL);
        $this->assertTrue($prepared->isSuccessful(), $prepared->getOutput().$prepared->getErrorOutput());
        $this->assertStringContainsString('RESUMED '.str_repeat('a', 32), $prepared->getOutput());

        $helper = $this->directory.'/pc/.git/stocks-preview/build-'.str_repeat('a', 32).'/PreviewFileSwap.php';
        file_put_contents($helper, 'tampered');
        $tampered = $this->powershell($this->directory.'/pc', <<<'POWERSHELL'
. ./scripts/git_branch_helpers.ps1
. ./scripts/git_preview_helpers.ps1
Read-StocksPreviewReceipt -Id ('a' * 32) -Branch main -Commit $env:STOCKS_TEST_COMMIT -MainCommit $env:STOCKS_TEST_COMMIT
POWERSHELL);
        $this->assertFalse($tampered->isSuccessful());
        $this->assertStringContainsString('Prepared preview file changed', $tampered->getErrorOutput());
    }

    public function test_preparation_requires_local_environment_and_never_runs_database_updates(): void
    {
        $this->fails('pc', '.env is missing', 'gitmain');
        file_put_contents($this->directory.'/pc/.env', "APP_ENV=production\n");
        $this->fails('pc', 'APP_ENV=local', 'gitmain');
        file_put_contents($this->directory.'/pc/.env', "APP_ENV=local\n");
        $this->environment['STOCKS_TEST_PREPARE'] = '1';
        $result = $this->workflow('pc', 'gitmain');
        $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
        $this->assertStringContainsString('scripts/update.php --target=local --prepare', $result->getOutput());
        $this->assertStringContainsString('artisan config:clear', $result->getOutput());
        $this->assertStringContainsString('npm run build', $result->getOutput());
        $this->assertStringNotContainsString('artisan migrate', $result->getOutput());
        $this->assertStringNotContainsString('artisan app:update', $result->getOutput());
        $this->environment['STOCKS_TEST_PREPARE'] = 'fail';
        $this->fails('pc', 'Dependency preparation failed', 'gitstart', 'prepare-failure');
        $this->assertSame('codex/prepare-failure', $this->git($this->directory.'/pc', 'branch', '--show-current'));
        $this->assertStringContainsString('codex/prepare-failure', $this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/*'));
        $this->assertStringContainsString('codex/features/prepare-failure', $this->git($this->directory.'/origin.git', 'branch', '--list', 'codex/*'));
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
        $this->assertStringContainsString('function gitpush', $contents);
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
            $result = $this->powershell($this->directory.'/pc', <<<'POWERSHELL'
function gitpush { param([string]$message) Write-Output "LEGACY $message" }
. $env:STOCKS_TEST_PROFILE
gitpush 'Safe main release'
POWERSHELL);
            $this->assertTrue($result->isSuccessful(), $result->getOutput().$result->getErrorOutput());
            $this->assertStringContainsString($project === 'stocks' ? 'DISPATCH gitpush Safe main release' : 'LEGACY Safe main release', $result->getOutput());
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
if ($env:STOCKS_TEST_CI) {
    function gh { $global:LASTEXITCODE = 0; $env:STOCKS_TEST_CI }
}
if ($env:STOCKS_TEST_PROMPT) {
    function Read-Host { param([string]$Prompt) $env:STOCKS_TEST_PROMPT }
}
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
