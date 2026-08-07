<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class DeploymentWorkflowTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDeploymentDirectories = [];

    public function test_update_launcher_selects_local_and_cloudways_plans(): void
    {
        $local = $this->runPhpScript('scripts/update.php', '--target=local', '--dry-run');
        $cloudways = $this->runPhpScript('scripts/update.php', '--target=cloudways', '--dry-run');
        $cloudwaysPrepare = $this->runPhpScript(
            'scripts/update.php',
            '--target=cloudways',
            '--prepare',
            '--dry-run',
        );

        $this->assertTrue($local->isSuccessful(), $local->getErrorOutput());
        $this->assertStringContainsString('Update target: local', $local->getOutput());
        $this->assertStringContainsString('verified release artifact', $local->getOutput());
        $this->assertTrue($cloudways->isSuccessful(), $cloudways->getErrorOutput());
        $this->assertStringContainsString('guarded Cloudways production deployment', $cloudways->getOutput());
        $this->assertTrue($cloudwaysPrepare->isSuccessful(), $cloudwaysPrepare->getErrorOutput());
        $this->assertStringContainsString('maintenance mode before Cloudways Pull', $cloudwaysPrepare->getOutput());
    }

    public function test_composer_exposes_deployment_and_powershell_setup_commands(): void
    {
        $composer = json_decode(
            file_get_contents($this->projectPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertContains('Composer\\Config::disableProcessTimeout', $composer['scripts']['deploy']);
        $this->assertContains('@php scripts/update.php', $composer['scripts']['deploy']);
        $this->assertSame([
            'Composer\Config::disableProcessTimeout',
            '@php scripts/update.php --target=cloudways --prepare',
        ], $composer['scripts']['deploy:prepare']);
        $this->assertSame([
            'Composer\Config::disableProcessTimeout',
            'bash scripts/pdeploy_cloudways.sh',
        ], $composer['scripts']['pdeploy']);
        $this->assertContains(
            'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install_powershell_helpers.ps1',
            $composer['scripts']['setup:powershell'],
        );
    }

    public function test_php_platform_is_aligned_for_local_and_cloudways_deployments(): void
    {
        $composer = json_decode(
            file_get_contents($this->projectPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $continuousIntegrationWorkflow = file_get_contents($this->projectPath('.github/workflows/ci.yml'));
        $phpUnitConfiguration = file_get_contents($this->projectPath('phpunit.xml'));

        $this->assertSame('^8.4.1', $composer['require']['php']);
        $this->assertSame('8.4.1', $composer['config']['platform']['php']);
        $this->assertSame('^8.0', $composer['require']['symfony/http-client']);
        $this->assertSame('^8.0', $composer['require']['symfony/postmark-mailer']);
        $this->assertSame(2, substr_count($continuousIntegrationWorkflow, "php-version: '8.4'"));
        $this->assertStringContainsString(
            'run: composer check-platform-reqs --no-interaction',
            $continuousIntegrationWorkflow,
        );
        $this->assertStringContainsString('<env name="APP_KEY" value="base64:', $phpUnitConfiguration);
    }

    public function test_composer_exposes_the_local_queue_worker_command(): void
    {
        $composer = json_decode(
            file_get_contents($this->projectPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'Composer\\Config::disableProcessTimeout',
            '@php artisan queue:listen redis --queue=default --sleep=1 --tries=1 --timeout=0',
        ], $composer['scripts']['queues:local']);
    }

    public function test_composer_development_process_uses_the_platform_aware_launcher(): void
    {
        $composer = json_decode(
            file_get_contents($this->projectPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'Composer\\Config::disableProcessTimeout',
            '@php scripts/dev.php',
        ], $composer['scripts']['dev']);

        $windows = $this->runPhpScript('scripts/dev.php', '--dry-run=windows');
        $unix = $this->runPhpScript('scripts/dev.php', '--dry-run=unix');

        $this->assertTrue($windows->isSuccessful(), $windows->getErrorOutput());
        $this->assertStringContainsString('php artisan serve', $windows->getOutput());
        $this->assertStringContainsString('php artisan queue:listen', $windows->getOutput());
        $this->assertStringContainsString('php artisan schedule:work', $windows->getOutput());
        $this->assertStringContainsString('npm run dev', $windows->getOutput());
        $this->assertStringContainsString('--names=server,queue,scheduler,vite', $windows->getOutput());
        $this->assertStringNotContainsString('php artisan pail', $windows->getOutput());

        $this->assertTrue($unix->isSuccessful(), $unix->getErrorOutput());
        $this->assertStringContainsString('php artisan pail --timeout=0', $unix->getOutput());
        $this->assertStringContainsString('--names=server,queue,scheduler,logs,vite', $unix->getOutput());
    }

    public function test_stale_vite_cleanup_only_targets_the_vite_entry_point(): void
    {
        $cleanup = file_get_contents($this->projectPath('scripts/dev-stop-stale-vite.mjs'));

        $this->assertStringContainsString("commandLine.Contains('\\\\node_modules\\\\vite\\\\bin\\\\vite.js')", $cleanup);
        $this->assertStringNotContainsString("commandLine.Contains('vite')", $cleanup);
    }

    public function test_cloudways_deployment_uses_the_verified_artifact_and_stock_update_flags(): void
    {
        $deployment = file_get_contents($this->projectPath('scripts/deploy_cloudways.sh'));

        $this->assertStringContainsString('flock -n 9', $deployment);
        $this->assertStringContainsString('php scripts/frontend-release.php verify', $deployment);
        $this->assertStringContainsString('frontend-build.sha256', $deployment);
        $this->assertStringContainsString(
            'php scripts/source-manifest.php prune-unlisted "$frontend_release_manifest_path"',
            $deployment,
        );
        $this->assertStringContainsString('tar -xzf "$frontend_release_archive"', $deployment);
        $this->assertStringContainsString('Resuming the interrupted Cloudways deployment.', $deployment);
        $this->assertStringContainsString('frontend_artifact_installed=true', $deployment);
        $this->assertStringContainsString('cloudways-deploy-maintenance', $deployment);
        $this->assertLessThan(
            strrpos($deployment, 'php artisan up'),
            strrpos($deployment, 'finalize_frontend_artifact'),
        );
        $this->assertStringContainsString(
            'php artisan app:update --no-interaction --skip-composer --skip-npm --skip-build',
            $deployment,
        );
        $this->assertStringContainsString(
            'composer check-platform-reqs --no-dev --no-interaction',
            $deployment,
        );
        $this->assertStringContainsString(
            'composer check-platform-reqs --lock --no-dev --no-interaction',
            $deployment,
        );
        $this->assertLessThan(
            strpos($deployment, 'composer install'),
            strpos($deployment, 'composer check-platform-reqs --lock'),
        );
        $this->assertLessThan(
            strrpos($deployment, 'composer check-platform-reqs'),
            strpos($deployment, 'composer install'),
        );
        $this->assertStringNotContainsString('--skip-frontend', $deployment);
        $this->assertStringNotContainsString('npm run build', $deployment);
        $this->assertLessThan(
            strpos($deployment, 'php scripts/source-manifest.php prune-unlisted'),
            strpos($deployment, "printf 'backend-started\\n'"),
        );
        $this->assertLessThan(
            strrpos($deployment, 'prepare_frontend_artifact'),
            strrpos($deployment, 'php scripts/source-manifest.php prune-unlisted'),
        );
    }

    public function test_cloudways_deployment_script_has_valid_bash_syntax(): void
    {
        foreach (['scripts/deploy_cloudways.sh', 'scripts/pdeploy_cloudways.sh'] as $script) {
            $syntax = new Process([
                $this->bashExecutable(),
                '-n',
                $this->projectPath($script),
            ], $this->projectPath());
            $syntax->run();

            $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
        }
    }

    public function test_cloudways_terminal_pull_deploys_main_with_one_command(): void
    {
        $deploymentDirectory = $this->createCloudwaysPullShellFixture();

        $deployed = $this->runCloudwaysPullShellFixture($deploymentDirectory);

        $this->assertTrue($deployed->isSuccessful(), $deployed->getErrorOutput());
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/git-fetched");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/git-merged");
        $this->assertDirectoryExists("{$deploymentDirectory}/public/build");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
        $this->assertStringContainsString(
            'Cloudways pull and deployment completed successfully.',
            $deployed->getOutput(),
        );
    }

    public function test_cloudways_terminal_pull_failure_restores_the_application(): void
    {
        $deploymentDirectory = $this->createCloudwaysPullShellFixture();
        file_put_contents("{$deploymentDirectory}/storage/framework/fail-git-merge", '1');

        $failed = $this->runCloudwaysPullShellFixture($deploymentDirectory);

        $this->assertFalse($failed->isSuccessful());
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/git-fetched");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/git-merged");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
        $this->assertStringContainsString(
            'restoring the application from maintenance mode',
            $failed->getErrorOutput(),
        );
    }

    public function test_cloudways_terminal_pull_without_git_keeps_the_application_online(): void
    {
        $deploymentDirectory = $this->createCloudwaysShellFixture();
        copy($this->projectPath('scripts/pdeploy_cloudways.sh'), "{$deploymentDirectory}/scripts/pdeploy_cloudways.sh");

        $failed = $this->runCloudwaysPullShellFixture($deploymentDirectory);

        $this->assertFalse($failed->isSuccessful());
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
        $this->assertStringContainsString('not a Git working tree', $failed->getErrorOutput());
    }

    public function test_cloudways_deployment_rolls_back_a_first_frontend_and_resumes_its_own_maintenance_mode(): void
    {
        $deploymentDirectory = $this->createCloudwaysShellFixture();
        file_put_contents("{$deploymentDirectory}/storage/framework/fail-app-update", '1');

        $failed = $this->runCloudwaysShellFixture($deploymentDirectory);

        $this->assertFalse($failed->isSuccessful());
        $this->assertDirectoryDoesNotExist("{$deploymentDirectory}/public/build");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");

        unlink("{$deploymentDirectory}/storage/framework/fail-app-update");
        $resumed = $this->runCloudwaysShellFixture($deploymentDirectory);

        $this->assertTrue($resumed->isSuccessful(), $resumed->getErrorOutput());
        $this->assertStringContainsString('Resuming the interrupted Cloudways deployment.', $resumed->getOutput());
        $this->assertStringContainsString('Cloudways deployment completed successfully.', $resumed->getOutput());
        $this->assertDirectoryExists("{$deploymentDirectory}/public/build");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
    }

    public function test_cloudways_pull_preparation_is_repeatable_and_the_deployment_resumes_it(): void
    {
        $deploymentDirectory = $this->createCloudwaysShellFixture();
        file_put_contents("{$deploymentDirectory}/app/Legacy.php", '<?php');

        $prepared = $this->runCloudwaysShellFixture($deploymentDirectory, '--prepare');
        $preparedAgain = $this->runCloudwaysShellFixture($deploymentDirectory, '--prepare');

        $this->assertTrue($prepared->isSuccessful(), $prepared->getErrorOutput());
        $this->assertTrue($preparedAgain->isSuccessful(), $preparedAgain->getErrorOutput());
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");

        $deployed = $this->runCloudwaysShellFixture($deploymentDirectory);

        $this->assertTrue($deployed->isSuccessful(), $deployed->getErrorOutput());
        $this->assertFileDoesNotExist("{$deploymentDirectory}/app/Legacy.php");
        $this->assertDirectoryExists("{$deploymentDirectory}/public/build");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
    }

    public function test_cloudways_source_pruning_failure_keeps_maintenance_mode_and_can_resume(): void
    {
        $deploymentDirectory = $this->createCloudwaysShellFixture();
        file_put_contents("{$deploymentDirectory}/app/Legacy.php", '<?php');
        file_put_contents("{$deploymentDirectory}/storage/framework/fail-source-prune", '1');

        $failed = $this->runCloudwaysShellFixture($deploymentDirectory);

        $this->assertFalse($failed->isSuccessful());
        $this->assertFileExists("{$deploymentDirectory}/app/Legacy.php");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");

        unlink("{$deploymentDirectory}/storage/framework/fail-source-prune");
        $resumed = $this->runCloudwaysShellFixture($deploymentDirectory);

        $this->assertTrue($resumed->isSuccessful(), $resumed->getErrorOutput());
        $this->assertFileDoesNotExist("{$deploymentDirectory}/app/Legacy.php");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
    }

    public function test_dispatcher_allowlists_fetch_and_push_remotes_and_uses_the_repository_root(): void
    {
        $installer = file_get_contents($this->projectPath('scripts/install_powershell_helpers.ps1'));

        $this->assertStringContainsString('ITStudioAT/(?:schooltool|stocks)', $installer);
        $this->assertStringContainsString('remote get-url --all --push origin', $installer);
        $this->assertStringContainsString('Push-Location -LiteralPath `$repositoryRoot', $installer);
        $this->assertStringContainsString('Join-Path `$repositoryRoot \'scripts/gitpush.ps1\'', $installer);
        $this->assertStringNotContainsString('C:\\laravel\\schooltool', $installer);
        $this->assertStringContainsString(
            'Cloudways terminal: run composer pdeploy',
            file_get_contents($this->projectPath('scripts/git_helpers.ps1')),
        );
    }

    public function test_powershell_update_helper_checks_and_updates_composer_and_npm_packages(): void
    {
        $installer = file_get_contents($this->projectPath('scripts/install_powershell_helpers.ps1'));

        $this->assertStringContainsString('function mu', $installer);
        $this->assertMatchesRegularExpression(
            '/composer outdated --direct.*npmExecutable outdated.*composer update.*npmExecutable update/s',
            $installer,
        );
        $this->assertStringContainsString("'npm.cmd'", $installer);
        $this->assertStringNotContainsString('& npm update', $installer);
        $this->assertStringContainsString("throw 'Composer package update failed.'", $installer);
        $this->assertStringContainsString("throw 'npm package update failed.'", $installer);
        $this->assertStringContainsString("'PowerShell\\Microsoft.PowerShell_profile.ps1'", $installer);
        $this->assertStringContainsString("'WindowsPowerShell\\Microsoft.PowerShell_profile.ps1'", $installer);
    }

    public function test_source_manifest_rejects_unlisted_tracked_files(): void
    {
        $manifestName = 'deployment-test-'.bin2hex(random_bytes(4)).'.sha256';
        $manifestPath = "storage/framework/{$manifestName}";
        $absoluteManifestPath = $this->projectPath($manifestPath);

        try {
            file_put_contents($absoluteManifestPath, hash('sha256', file_get_contents($this->projectPath('artisan')))."  artisan\n");

            $verify = $this->runPhpScript('scripts/source-manifest.php', 'verify', $manifestPath);

            $this->assertFalse($verify->isSuccessful());
            $this->assertStringContainsString('unlisted:', $verify->getErrorOutput());
        } finally {
            if (is_file($absoluteManifestPath)) {
                unlink($absoluteManifestPath);
            }
        }
    }

    public function test_cloudways_release_verification_succeeds_without_git_metadata(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();

        $this->assertDirectoryDoesNotExist("{$deploymentDirectory}/.git");

        $verify = $this->runPhpScriptIn($deploymentDirectory, 'scripts/frontend-release.php', 'verify');

        $this->assertTrue($verify->isSuccessful(), $verify->getErrorOutput());
    }

    public function test_no_git_source_verification_rejects_unlisted_changed_and_missing_files(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        mkdir("{$deploymentDirectory}/app");
        file_put_contents("{$deploymentDirectory}/app/Unexpected.php", '<?php');

        $unlisted = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/source-manifest.php',
            'verify',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($unlisted->isSuccessful());
        $this->assertStringContainsString('unlisted: app/Unexpected.php', $unlisted->getErrorOutput());

        unlink("{$deploymentDirectory}/app/Unexpected.php");
        rmdir("{$deploymentDirectory}/app");
        file_put_contents("{$deploymentDirectory}/artisan", "changed\n");
        $changed = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/source-manifest.php',
            'verify',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($changed->isSuccessful());
        $this->assertStringContainsString('changed: artisan', $changed->getErrorOutput());

        unlink("{$deploymentDirectory}/artisan");
        $missing = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/source-manifest.php',
            'verify',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($missing->isSuccessful());
        $this->assertStringContainsString('missing: artisan', $missing->getErrorOutput());
    }

    public function test_source_pruning_removes_only_unlisted_managed_files(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();

        foreach (['app/Http/Middleware', 'bootstrap/cache', 'public/storage', 'storage/framework'] as $relativeDirectory) {
            mkdir("{$deploymentDirectory}/{$relativeDirectory}", 0777, true);
        }

        file_put_contents("{$deploymentDirectory}/app/Http/Middleware/Legacy.php", '<?php');
        file_put_contents("{$deploymentDirectory}/bootstrap/cache/runtime.php", '<?php');
        file_put_contents("{$deploymentDirectory}/public/storage/runtime.txt", 'runtime');
        file_put_contents("{$deploymentDirectory}/storage/framework/runtime.txt", 'runtime');
        file_put_contents("{$deploymentDirectory}/.env", 'APP_ENV=production');

        $prune = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/source-manifest.php',
            'prune-unlisted',
            'deployment/source-manifest.sha256',
        );

        $this->assertTrue($prune->isSuccessful(), $prune->getErrorOutput());
        $this->assertStringContainsString(
            'Pruned stale deployment source file: app/Http/Middleware/Legacy.php',
            $prune->getOutput(),
        );
        $this->assertFileDoesNotExist("{$deploymentDirectory}/app/Http/Middleware/Legacy.php");
        $this->assertFileExists("{$deploymentDirectory}/artisan");
        $this->assertFileExists("{$deploymentDirectory}/bootstrap/cache/runtime.php");
        $this->assertFileExists("{$deploymentDirectory}/public/storage/runtime.txt");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/runtime.txt");
        $this->assertFileExists("{$deploymentDirectory}/.env");
    }

    public function test_source_pruning_refuses_all_removal_when_expected_source_changed_or_missing(): void
    {
        $changedDirectory = $this->createDeploymentFixture();
        mkdir("{$changedDirectory}/app");
        file_put_contents("{$changedDirectory}/app/Legacy.php", '<?php');
        file_put_contents("{$changedDirectory}/artisan", "changed\n");

        $changed = $this->runPhpScriptIn(
            $changedDirectory,
            'scripts/source-manifest.php',
            'prune-unlisted',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($changed->isSuccessful());
        $this->assertStringContainsString('changed: artisan', $changed->getErrorOutput());
        $this->assertFileExists("{$changedDirectory}/app/Legacy.php");

        $missingDirectory = $this->createDeploymentFixture();
        mkdir("{$missingDirectory}/app");
        file_put_contents("{$missingDirectory}/app/Legacy.php", '<?php');
        unlink("{$missingDirectory}/artisan");

        $missing = $this->runPhpScriptIn(
            $missingDirectory,
            'scripts/source-manifest.php',
            'prune-unlisted',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($missing->isSuccessful());
        $this->assertStringContainsString('missing: artisan', $missing->getErrorOutput());
        $this->assertFileExists("{$missingDirectory}/app/Legacy.php");
    }

    public function test_source_pruning_rejects_an_incomplete_manifest_without_removing_files(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        mkdir("{$deploymentDirectory}/app");
        file_put_contents("{$deploymentDirectory}/app/Legacy.php", '<?php');
        file_put_contents(
            "{$deploymentDirectory}/deployment/source-manifest.sha256",
            $this->normalizedFileHash("{$deploymentDirectory}/artisan")."  artisan\n",
        );

        $prune = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/source-manifest.php',
            'prune-unlisted',
            'deployment/source-manifest.sha256',
        );

        $this->assertFalse($prune->isSuccessful());
        $this->assertStringContainsString('cannot safely prune files because it omits', $prune->getErrorOutput());
        $this->assertFileExists("{$deploymentDirectory}/app/Legacy.php");
        $this->assertFileExists("{$deploymentDirectory}/scripts/source-manifest.php");
    }

    public function test_no_git_release_verification_rejects_marker_and_expected_commit_mismatches(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        file_put_contents("{$deploymentDirectory}/deployment/source-commit", str_repeat('b', 40)."\n");

        $archiveMismatch = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
        );

        $this->assertFalse($archiveMismatch->isSuccessful());
        $this->assertStringContainsString(
            'The frontend archive source marker does not match its release.',
            $archiveMismatch->getErrorOutput(),
        );

        file_put_contents("{$deploymentDirectory}/deployment/source-commit", str_repeat('a', 40)."\n");
        $explicitCommitMismatch = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
            str_repeat('b', 40),
        );

        $this->assertFalse($explicitCommitMismatch->isSuccessful());
        $this->assertStringContainsString('not '.str_repeat('b', 40), $explicitCommitMismatch->getErrorOutput());
    }

    public function test_no_git_release_verification_rejects_a_modified_frontend_archive(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        file_put_contents(
            "{$deploymentDirectory}/deployment/frontend-build.tar.gz",
            'tampered',
            FILE_APPEND,
        );

        $verify = $this->runPhpScriptIn($deploymentDirectory, 'scripts/frontend-release.php', 'verify');

        $this->assertFalse($verify->isSuccessful());
        $this->assertStringContainsString(
            'The frontend release archive checksum does not match.',
            $verify->getErrorOutput(),
        );
    }

    public function test_no_git_release_verification_rejects_missing_or_invalid_release_markers(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        unlink("{$deploymentDirectory}/deployment/source-commit");

        $missingOuterMarker = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
        );

        $this->assertFalse($missingOuterMarker->isSuccessful());
        $this->assertStringContainsString(
            'The frontend release source marker is missing.',
            $missingOuterMarker->getErrorOutput(),
        );

        file_put_contents("{$deploymentDirectory}/deployment/source-commit", "invalid\n");
        $invalidOuterMarker = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
        );

        $this->assertFalse($invalidOuterMarker->isSuccessful());
        $this->assertStringContainsString(
            'The frontend release source commit is invalid.',
            $invalidOuterMarker->getErrorOutput(),
        );

        file_put_contents("{$deploymentDirectory}/deployment/source-commit", str_repeat('a', 40)."\n");
        unlink("{$deploymentDirectory}/public/build/deployment-source.txt");
        $this->writeDeploymentFixtureArchive($deploymentDirectory);
        $missingInnerMarker = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
        );

        $this->assertFalse($missingInnerMarker->isSuccessful());
        $this->assertStringContainsString(
            'The frontend archive source marker does not match its release.',
            $missingInnerMarker->getErrorOutput(),
        );
    }

    private function runPhpScript(string ...$arguments): Process
    {
        return $this->runPhpScriptIn($this->projectPath(), ...$arguments);
    }

    private function runPhpScriptIn(string $workingDirectory, string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, ...$arguments], $workingDirectory);
        $process->run();

        return $process;
    }

    private function createDeploymentFixture(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stocks-deployment-test-'.bin2hex(random_bytes(8));
        $this->temporaryDeploymentDirectories[] = $directory;

        foreach (['deployment', 'public/build', 'scripts'] as $relativeDirectory) {
            mkdir($directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory), 0777, true);
        }

        copy($this->projectPath('scripts/deploy_cloudways.sh'), "{$directory}/scripts/deploy_cloudways.sh");
        copy($this->projectPath('scripts/frontend-release.php'), "{$directory}/scripts/frontend-release.php");
        copy($this->projectPath('scripts/source-manifest.php'), "{$directory}/scripts/source-manifest.php");
        file_put_contents("{$directory}/artisan", "fixture-artisan\n");
        file_put_contents("{$directory}/composer.json", "{}\n");
        file_put_contents("{$directory}/composer.lock", "{}\n");
        file_put_contents("{$directory}/public/build/manifest.json", "{}\n");
        file_put_contents("{$directory}/public/build/deployment-source.txt", str_repeat('a', 40)."\n");
        file_put_contents("{$directory}/deployment/source-commit", str_repeat('a', 40)."\n");

        $manifestPaths = [
            'artisan',
            'composer.json',
            'composer.lock',
            'scripts/deploy_cloudways.sh',
            'scripts/frontend-release.php',
            'scripts/source-manifest.php',
        ];
        $manifest = collect($manifestPaths)
            ->map(fn (string $relativePath): string => $this->normalizedFileHash("{$directory}/{$relativePath}")."  {$relativePath}")
            ->implode("\n")."\n";
        file_put_contents("{$directory}/deployment/source-manifest.sha256", $manifest);

        $this->writeDeploymentFixtureArchive($directory);

        return str_replace('\\', '/', $directory);
    }

    private function createCloudwaysShellFixture(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stocks-deployment-test-'.bin2hex(random_bytes(8));
        $this->temporaryDeploymentDirectories[] = $directory;

        foreach (['app', 'artifact', 'bin', 'deployment', 'public', 'scripts', 'storage/framework'] as $relativeDirectory) {
            mkdir($directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory), 0777, true);
        }

        copy($this->projectPath('scripts/deploy_cloudways.sh'), "{$directory}/scripts/deploy_cloudways.sh");
        file_put_contents("{$directory}/artisan", "fixture\n");
        file_put_contents("{$directory}/deployment/source-commit", str_repeat('a', 40)."\n");
        file_put_contents("{$directory}/deployment/source-manifest.sha256", "fixture\n");
        file_put_contents("{$directory}/deployment/frontend-build.sha256", "fixture\n");
        file_put_contents("{$directory}/artifact/manifest.json", "{}\n");
        file_put_contents("{$directory}/artifact/deployment-source.txt", str_repeat('a', 40)."\n");

        $archive = new Process([
            'tar',
            '-czf',
            "{$directory}/deployment/frontend-build.tar.gz",
            '-C',
            "{$directory}/artifact",
            '.',
        ], $directory);
        $archive->run();
        $this->assertTrue($archive->isSuccessful(), $archive->getErrorOutput());

        $this->writeExecutable("{$directory}/bin/php", <<<'BASH'
#!/usr/bin/env bash
set -e

if [ "${1:-}" = "scripts/source-manifest.php" ] && [ "${2:-}" = "prune-unlisted" ]; then
    if [ -f storage/framework/fail-source-prune ]; then
        exit 1
    fi

    rm -f app/Legacy.php
    exit 0
fi

if [ "${1:-}" != "artisan" ]; then
    exit 0
fi

case "${2:-}" in
    down)
        touch storage/framework/down
        ;;
    up)
        rm -f storage/framework/down
        ;;
    app:update)
        if [ -f storage/framework/fail-app-update ]; then
            exit 1
        fi
        ;;
esac
BASH);
        $this->writeExecutable("{$directory}/bin/composer", "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable("{$directory}/bin/flock", "#!/usr/bin/env bash\nexit 0\n");

        return str_replace('\\', '/', $directory);
    }

    private function runCloudwaysShellFixture(string $directory, string ...$arguments): Process
    {
        $bashDirectory = $this->bashPath($directory);
        $process = new Process([
            $this->bashExecutable(),
            '-lc',
            'export PATH="$1/bin:$PATH"; bash "$1/scripts/deploy_cloudways.sh" "${@:2}"',
            'stocks-deployment-test',
            $bashDirectory,
            ...$arguments,
        ], $directory);
        $process->run();

        return $process;
    }

    private function createCloudwaysPullShellFixture(): string
    {
        $directory = $this->createCloudwaysShellFixture();
        copy($this->projectPath('scripts/pdeploy_cloudways.sh'), "{$directory}/scripts/pdeploy_cloudways.sh");

        $this->writeExecutable("{$directory}/bin/git", <<<'BASH'
#!/usr/bin/env bash
set -e

case "${1:-}" in
    rev-parse)
        echo true
        ;;
    branch)
        echo main
        ;;
    status)
        ;;
    fetch)
        touch storage/framework/git-fetched
        ;;
    merge-base)
        ;;
    merge)
        if [ -f storage/framework/fail-git-merge ]; then
            exit 1
        fi

        touch storage/framework/git-merged
        ;;
    *)
        exit 1
        ;;
esac
BASH);

        return $directory;
    }

    private function runCloudwaysPullShellFixture(string $directory): Process
    {
        $bashDirectory = $this->bashPath($directory);
        $process = new Process([
            $this->bashExecutable(),
            '-lc',
            'export PATH="$1/bin:$PATH"; bash "$1/scripts/pdeploy_cloudways.sh"',
            'stocks-deployment-test',
            $bashDirectory,
        ], $directory);
        $process->run();

        return $process;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, str_replace(["\r\n", "\r"], "\n", $contents));
        chmod($path, 0777);
    }

    private function writeDeploymentFixtureArchive(string $directory): void
    {
        $archive = new Process([
            'tar',
            '-czf',
            "{$directory}/deployment/frontend-build.tar.gz",
            '-C',
            "{$directory}/public/build",
            '.',
        ], $directory);
        $archive->run();
        $this->assertTrue($archive->isSuccessful(), $archive->getErrorOutput());
        $archiveHash = hash_file('sha256', "{$directory}/deployment/frontend-build.tar.gz");
        $this->assertIsString($archiveHash);
        file_put_contents(
            "{$directory}/deployment/frontend-build.sha256",
            "{$archiveHash}  frontend-build.tar.gz\n",
        );
    }

    private function normalizedFileHash(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
    }

    private function bashExecutable(): string
    {
        $gitBash = 'C:\\Program Files\\Git\\bin\\bash.exe';

        return PHP_OS_FAMILY === 'Windows' && is_file($gitBash)
            ? $gitBash
            : 'bash';
    }

    private function bashPath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        if (PHP_OS_FAMILY !== 'Windows' || preg_match('/^(?<drive>[A-Za-z]):(?<path>\/.*)$/', $normalizedPath, $matches) !== 1) {
            return $normalizedPath;
        }

        return '/'.strtolower($matches['drive']).$matches['path'];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDeploymentDirectories as $directory) {
            $this->removeTemporaryDeploymentDirectory($directory);
        }

        parent::tearDown();
    }

    private function removeTemporaryDeploymentDirectory(string $directory): void
    {
        if (! is_dir($directory) || ! str_starts_with(basename($directory), 'stocks-deployment-test-')) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($directory);
    }

    private function projectPath(string $relativePath = ''): string
    {
        $projectDirectory = dirname(__DIR__, 2);

        return $relativePath === ''
            ? $projectDirectory
            : $projectDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
