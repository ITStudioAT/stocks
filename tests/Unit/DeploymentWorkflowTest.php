<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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
        $workflow = Yaml::parse($continuousIntegrationWorkflow);
        $phpJobs = [];
        foreach ($workflow['jobs'] as $name => $job) {
            foreach ($job['steps'] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
                    $this->assertSame('8.4', $step['with']['php-version'], "PHP version in {$name}");
                    $phpJobs[] = $name;
                }
            }
        }
        $this->assertContains('php', $phpJobs);
        $this->assertContains('release-integrity', $phpJobs);
        $this->assertStringContainsString(
            'run: composer check-platform-reqs --no-interaction',
            $continuousIntegrationWorkflow,
        );
        $this->assertStringContainsString('<env name="APP_KEY" value="base64:', $phpUnitConfiguration);
    }

    public function test_ci_audits_dependencies_and_pins_actions_to_release_commits(): void
    {
        $continuousIntegrationWorkflow = file_get_contents($this->projectPath('.github/workflows/ci.yml'));
        $continuousIntegrationConfiguration = Yaml::parse($continuousIntegrationWorkflow);

        $this->assertArrayHasKey('jobs', $continuousIntegrationConfiguration);
        $this->assertSame(['contents' => 'read'], $continuousIntegrationConfiguration['permissions']);
        $approvedActions = [
            'actions/checkout' => '11d5960a326750d5838078e36cf38b85af677262',
            'actions/setup-node' => '49933ea5288caeca8642d1e84afbd3f7d6820020',
            'shivammathur/setup-php' => 'f3e473d116dcccaddc5834248c87452386958240',
        ];
        foreach ($continuousIntegrationConfiguration['jobs'] as $name => $job) {
            foreach ($job['steps'] as $step) {
                if (! isset($step['uses'])) {
                    continue;
                }
                [$action, $revision] = explode('@', $step['uses'], 2);
                $this->assertArrayHasKey($action, $approvedActions);
                $this->assertSame($approvedActions[$action], $revision, "Action revision in {$name}");
                if ($action === 'actions/checkout') {
                    $this->assertFalse($step['with']['persist-credentials'] ?? true, "Checkout credentials in {$name}");
                }
            }
        }
        $this->assertStringContainsString('run: composer audit --locked', $continuousIntegrationWorkflow);
        $this->assertStringContainsString('run: npm audit', $continuousIntegrationWorkflow);
        $this->assertStringContainsString('run: npm ci --ignore-scripts --no-fund', $continuousIntegrationWorkflow);
        $this->assertStringContainsString('run: php scripts/verify-release-commit.php', $continuousIntegrationWorkflow);
        $this->assertStringContainsString(
            'run: php scripts/frontend-release.php verify-build "$(git rev-parse HEAD^)"',
            $continuousIntegrationWorkflow,
        );
        $this->assertStringNotContainsString('--no-audit', $continuousIntegrationWorkflow);
    }

    public function test_tailwind_release_build_only_scans_versioned_application_sources(): void
    {
        $styleSheet = file_get_contents($this->projectPath('resources/css/app.css'));

        $this->assertStringContainsString("@import 'tailwindcss' source(none);", $styleSheet);
        $this->assertStringContainsString("@source '../**/*.blade.php';", $styleSheet);
        $this->assertStringContainsString("@source '../**/*.js';", $styleSheet);
        $this->assertStringContainsString("@source '../**/*.vue';", $styleSheet);
        $this->assertStringNotContainsString('storage/framework/views', $styleSheet);
        $this->assertStringNotContainsString('vendor/', $styleSheet);
    }

    public function test_dependabot_monitors_all_dependency_ecosystems(): void
    {
        $dependabot = Yaml::parseFile($this->projectPath('.github/dependabot.yml'));
        $updates = $dependabot['updates'];

        $this->assertSame(2, $dependabot['version']);
        $this->assertSame(['composer', 'npm', 'github-actions'], array_column($updates, 'package-ecosystem'));

        foreach ($updates as $update) {
            $this->assertSame('/', $update['directory']);
            $this->assertSame('weekly', $update['schedule']['interval']);
        }
    }

    public function test_tinker_is_only_a_development_dependency(): void
    {
        $composer = json_decode(
            file_get_contents($this->projectPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $composerLock = json_decode(
            file_get_contents($this->projectPath('composer.lock')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayNotHasKey('laravel/tinker', $composer['require']);
        $this->assertSame('^3.0', $composer['require-dev']['laravel/tinker']);
        $this->assertNotContains('laravel/tinker', array_column($composerLock['packages'], 'name'));
        $this->assertContains('laravel/tinker', array_column($composerLock['packages-dev'], 'name'));
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

        $this->assertStringContainsString("commandLine.Contains('node_modules')", $cleanup);
        $this->assertStringContainsString(
            "commandLine.Contains('vite\\\\bin\\\\vite.js')",
            $cleanup,
        );
        $this->assertStringContainsString("commandLine.Contains('vite/bin/vite.js')", $cleanup);
        $this->assertStringNotContainsString("commandLine.Contains('vite')", $cleanup);
        $this->assertStringContainsString("unlinkSync('public/hot')", $cleanup);
    }

    public function test_local_frontend_dependency_install_stops_vite_before_npm_ci_on_windows(): void
    {
        $updateLauncher = file_get_contents($this->projectPath('scripts/update.php'));
        $installFunctionPosition = strpos($updateLauncher, 'function installFrontendDependencies(): int');
        $runLocalUpdatePosition = strpos($updateLauncher, 'function runLocalUpdate(bool $prepareOnly): int');

        $this->assertIsInt($installFunctionPosition);
        $this->assertIsInt($runLocalUpdatePosition);

        $installFunction = substr(
            $updateLauncher,
            $installFunctionPosition,
            $runLocalUpdatePosition - $installFunctionPosition,
        );
        $cleanupPosition = strpos($installFunction, "updateProjectPath('scripts/dev-stop-stale-vite.mjs')");
        $npmCiPosition = strpos($installFunction, "['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund']");

        $this->assertStringContainsString("if (PHP_OS_FAMILY === 'Windows')", $installFunction);
        $this->assertStringContainsString("'--strict'", $installFunction);
        $this->assertStringContainsString('if ($cleanupExitCode !== 0)', $installFunction);
        $this->assertStringContainsString('return $cleanupExitCode;', $installFunction);
        $this->assertIsInt($cleanupPosition);
        $this->assertIsInt($npmCiPosition);
        $this->assertLessThan($npmCiPosition, $cleanupPosition);
    }

    public function test_cloudways_deployment_uses_the_verified_artifact_and_stock_update_flags(): void
    {
        $deployment = file_get_contents($this->projectPath('scripts/deploy_cloudways.sh'));

        $this->assertStringContainsString('flock -n 9', $deployment);
        $this->assertStringContainsString('php scripts/frontend-release.php verify', $deployment);
        $this->assertStringContainsString('frontend-build.sha256', $deployment);
        $this->assertStringContainsString('contains a public source commit marker', $deployment);
        $this->assertStringNotContainsString('artifact_source_commit', $deployment);
        $this->assertStringContainsString(
            'php scripts/source-manifest.php prune-unlisted "$frontend_release_manifest_path"',
            $deployment,
        );
        $this->assertStringContainsString(
            'php scripts/frontend-release.php extract-to "$frontend_artifact_directory"',
            $deployment,
        );
        $this->assertStringContainsString('php scripts/frontend-release.php validate-build', $deployment);
        $this->assertStringNotContainsString('tar -xzf "$frontend_release_archive"', $deployment);
        $this->assertStringContainsString('Resuming the interrupted Cloudways deployment.', $deployment);
        $this->assertStringContainsString('frontend_artifact_installed=true', $deployment);
        $this->assertStringContainsString('cloudways-deploy-maintenance', $deployment);
        $this->assertLessThan(
            strrpos($deployment, 'php artisan up'),
            strrpos($deployment, 'finalize_frontend_artifact'),
        );
        $this->assertStringContainsString(
            'php artisan app:update --production --no-interaction --skip-composer --skip-npm --skip-build',
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
        $this->assertFileDoesNotExist("{$deploymentDirectory}/public/build/deployment-source.txt");
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

    public function test_cloudways_terminal_pull_uses_the_platform_api_when_git_metadata_is_missing(): void
    {
        $deploymentDirectory = $this->createCloudwaysApiPullShellFixture();

        $deployed = $this->runCloudwaysPullShellFixture($deploymentDirectory);

        $this->assertTrue($deployed->isSuccessful(), $deployed->getErrorOutput());
        $this->assertDirectoryDoesNotExist("{$deploymentDirectory}/.git");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-api-checked");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-api-pulled");
        $this->assertDirectoryExists("{$deploymentDirectory}/public/build");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
        $this->assertStringContainsString(
            'Cloudways platform Pull and deployment completed successfully.',
            $deployed->getOutput(),
        );
    }

    public function test_cloudways_platform_pull_failure_keeps_the_application_online(): void
    {
        $deploymentDirectory = $this->createCloudwaysApiPullShellFixture();
        file_put_contents("{$deploymentDirectory}/storage/framework/fail-cloudways-api-pull", '1');

        $failed = $this->runCloudwaysPullShellFixture($deploymentDirectory);

        $this->assertFalse($failed->isSuccessful());
        $this->assertDirectoryDoesNotExist("{$deploymentDirectory}/.git");
        $this->assertFileExists("{$deploymentDirectory}/storage/framework/cloudways-api-checked");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-api-pulled");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/down");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/storage/framework/cloudways-deploy-maintenance");
        $this->assertStringContainsString(
            'Cloudways did not complete the platform Pull; deployment was not started.',
            $failed->getErrorOutput(),
        );
        $this->assertStringContainsString(
            'restoring the application from maintenance mode',
            $failed->getErrorOutput(),
        );
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
            'Cloudways terminal (including Pull-managed applications): run composer pdeploy',
            file_get_contents($this->projectPath('scripts/git_helpers.ps1')),
        );
    }

    public function test_gitpush_rejects_an_existing_remote_tag_before_mutating_the_release(): void
    {
        $gitHelpers = file_get_contents($this->projectPath('scripts/git_helpers.ps1'));
        $gitPushPosition = strpos($gitHelpers, 'function gitpush');

        $this->assertIsInt($gitPushPosition);

        $remoteTagCheckPosition = strpos(
            $gitHelpers,
            'Assert-StocksRemoteReleaseTagIsAvailable -Version $version',
            $gitPushPosition,
        );
        $synchronizePosition = strpos($gitHelpers, "Invoke-StocksCommand 'Synchronizing main before the release...'", $gitPushPosition);
        $localTagCheckPosition = strpos(
            $gitHelpers,
            'Test-StocksLocalReleaseTagCanResume -Version $version',
            $gitPushPosition,
        );
        $preparePosition = strpos($gitHelpers, "Invoke-StocksCommand 'Preparing local dependencies...'", $gitPushPosition);
        $releaseChecksPosition = strpos($gitHelpers, 'Invoke-StocksReleaseChecks -Full:$Full', $gitPushPosition);
        $sourceCommitPosition = strpos($gitHelpers, 'git commit -m $message', $gitPushPosition);

        $this->assertIsInt($remoteTagCheckPosition);
        $this->assertIsInt($synchronizePosition);
        $this->assertIsInt($localTagCheckPosition);
        $this->assertIsInt($preparePosition);
        $this->assertIsInt($releaseChecksPosition);
        $this->assertIsInt($sourceCommitPosition);
        $this->assertLessThan($synchronizePosition, $remoteTagCheckPosition);
        $this->assertLessThan($localTagCheckPosition, $synchronizePosition);
        $this->assertLessThan($preparePosition, $localTagCheckPosition);
        $this->assertLessThan($releaseChecksPosition, $remoteTagCheckPosition);
        $this->assertLessThan($sourceCommitPosition, $remoteTagCheckPosition);
        $this->assertStringContainsString(
            'is already published as $tag on origin. Choose an unused version. No release changes were made.',
            $gitHelpers,
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

    public function test_powershell_ssh_helper_dispatches_trusted_project_remotes(): void
    {
        $installer = file_get_contents($this->projectPath('scripts/install_powershell_helpers.ps1'));

        $this->assertStringContainsString('function sshx', $installer);
        $this->assertStringContainsString('# >>> project SSH dispatcher >>>', $installer);
        $this->assertStringContainsString('@($sshStartMarker, $sshEndMarker)', $installer);
        $this->assertStringContainsString('$profileContent += $sshManagedBlock', $installer);
        $this->assertStringContainsString('git rev-parse --show-toplevel', $installer);
        $this->assertStringContainsString('remote get-url origin', $installer);
        $this->assertStringContainsString('ITStudioAT/(?<project>schooltool|stocks)', $installer);
        $this->assertMatchesRegularExpression(
            "/schooltool = 'sftp_schooltool_at@165\\.227\\.156\\.99'.*stocks = 'sftp_gkstocks_admin@165\\.227\\.156\\.99'/s",
            $installer,
        );
        $this->assertStringContainsString('Get-Command ssh.exe', $installer);
        $this->assertStringContainsString("'C:\\Program Files\\Git\\usr\\bin\\ssh.exe'", $installer);
        $this->assertStringNotContainsString('C:\\laravel\\schooltool', $installer);
        $this->assertStringNotContainsString('C:\\laravel\\stocks', $installer);
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
        $this->assertFileDoesNotExist("{$deploymentDirectory}/public/build/deployment-source.txt");
    }

    public function test_release_commit_verifier_accepts_exactly_the_four_deployment_files(): void
    {
        $deploymentDirectory = $this->createReleaseCommitFixture();

        $verify = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/verify-release-commit.php',
        );

        $this->assertTrue($verify->isSuccessful(), $verify->getErrorOutput());
        $this->assertStringContainsString(
            'Deployment release commit verified for source',
            $verify->getOutput(),
        );
    }

    public function test_release_commit_verifier_rejects_an_extra_source_file(): void
    {
        $deploymentDirectory = $this->createReleaseCommitFixture(includeUnexpectedSource: true);

        $verify = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/verify-release-commit.php',
        );

        $this->assertFalse($verify->isSuccessful());
        $this->assertStringContainsString(
            'unexpected: app/Unexpected.php',
            $verify->getErrorOutput(),
        );
    }

    public function test_frontend_release_creation_and_installation_keep_the_source_commit_private(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        $sourceCommit = str_repeat('b', 40);
        file_put_contents(
            "{$deploymentDirectory}/public/build/deployment-source.txt",
            str_repeat('a', 40)."\n",
        );

        $gitInit = new Process(['git', 'init'], $deploymentDirectory);
        $gitInit->run();
        $this->assertTrue($gitInit->isSuccessful(), $gitInit->getErrorOutput());

        $gitAdd = new Process([
            'git',
            'add',
            '--',
            'artisan',
            'composer.json',
            'composer.lock',
            'scripts/deploy_cloudways.sh',
            'scripts/frontend-release.php',
            'scripts/source-manifest.php',
        ], $deploymentDirectory);
        $gitAdd->run();
        $this->assertTrue($gitAdd->isSuccessful(), $gitAdd->getErrorOutput());

        $create = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'create',
            $sourceCommit,
        );

        $this->assertTrue($create->isSuccessful(), $create->getErrorOutput());
        $this->assertSame($sourceCommit, trim((string) file_get_contents(
            "{$deploymentDirectory}/deployment/source-commit",
        )));
        $this->assertFileDoesNotExist("{$deploymentDirectory}/public/build/deployment-source.txt");

        $archiveContents = new Process([
            'tar',
            '-tzf',
            "{$deploymentDirectory}/deployment/frontend-build.tar.gz",
        ], $deploymentDirectory);
        $archiveContents->run();

        $this->assertTrue($archiveContents->isSuccessful(), $archiveContents->getErrorOutput());
        $this->assertStringContainsString('manifest.json', $archiveContents->getOutput());
        $this->assertStringNotContainsString('deployment-source.txt', $archiveContents->getOutput());

        $install = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'install',
        );

        $this->assertTrue($install->isSuccessful(), $install->getErrorOutput());
        $this->assertFileExists("{$deploymentDirectory}/public/build/manifest.json");
        $this->assertFileDoesNotExist("{$deploymentDirectory}/public/build/deployment-source.txt");
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

    public function test_no_git_release_verification_rejects_an_expected_commit_mismatch(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        $explicitCommitMismatch = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify',
            str_repeat('b', 40),
        );

        $this->assertFalse($explicitCommitMismatch->isSuccessful());
        $this->assertStringContainsString('not '.str_repeat('b', 40), $explicitCommitMismatch->getErrorOutput());
    }

    public function test_no_git_release_verification_rejects_a_public_commit_marker_in_the_archive(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();
        file_put_contents(
            "{$deploymentDirectory}/public/build/deployment-source.txt",
            str_repeat('a', 40)."\n",
        );
        $this->writeDeploymentFixtureArchive($deploymentDirectory);

        $verify = $this->runPhpScriptIn($deploymentDirectory, 'scripts/frontend-release.php', 'verify');

        $this->assertFalse($verify->isSuccessful());
        $this->assertStringContainsString(
            'The frontend release archive contains a public source commit marker.',
            $verify->getErrorOutput(),
        );
    }

    public function test_release_verification_rejects_links_special_entries_and_path_traversal_before_extraction(): void
    {
        $cases = [
            ['name' => './assets/link.js', 'type' => '2', 'link_name' => 'manifest.json'],
            ['name' => './assets/hard-link.js', 'type' => '1', 'link_name' => 'manifest.json'],
            ['name' => './assets/device.js', 'type' => '3'],
            ['name' => '../outside.js', 'type' => '0', 'contents' => 'outside'],
        ];

        foreach ($cases as $maliciousEntry) {
            $deploymentDirectory = $this->createDeploymentFixture();
            $this->writeRawFrontendArchive($deploymentDirectory, [
                ['name' => './manifest.json', 'contents' => '{}'],
                $maliciousEntry,
            ]);

            $verify = $this->runPhpScriptIn($deploymentDirectory, 'scripts/frontend-release.php', 'verify');

            $this->assertFalse($verify->isSuccessful());
            $this->assertMatchesRegularExpression(
                '/link or special entry|path traversal|unsafe path/',
                $verify->getErrorOutput(),
            );
            $this->assertFileDoesNotExist(dirname($deploymentDirectory).'/outside.js');
        }
    }

    public function test_release_verification_rejects_unlisted_and_server_executable_assets(): void
    {
        $unlistedDirectory = $this->createDeploymentFixture();
        $this->writeRawFrontendArchive($unlistedDirectory, [
            ['name' => './manifest.json', 'contents' => '{}'],
            ['name' => './assets/unlisted.js', 'contents' => 'alert(1)'],
        ]);

        $unlisted = $this->runPhpScriptIn($unlistedDirectory, 'scripts/frontend-release.php', 'verify');

        $this->assertFalse($unlisted->isSuccessful());
        $this->assertStringContainsString('unexpected: assets/unlisted.js', $unlisted->getErrorOutput());

        $executableDirectory = $this->createDeploymentFixture();
        $this->writeRawFrontendArchive($executableDirectory, [
            [
                'name' => './manifest.json',
                'contents' => json_encode(['entry' => ['file' => 'assets/shell.php']], JSON_THROW_ON_ERROR),
            ],
            ['name' => './assets/shell.php', 'contents' => '<?php echo "unsafe";'],
        ]);

        $executable = $this->runPhpScriptIn($executableDirectory, 'scripts/frontend-release.php', 'verify');

        $this->assertFalse($executable->isSuccessful());
        $this->assertStringContainsString('disallowed asset extension', $executable->getErrorOutput());
    }

    public function test_release_verification_rejects_executable_and_special_permission_bits(): void
    {
        $cases = [
            [
                ['name' => './manifest.json', 'contents' => '{}', 'mode' => 0755],
                'file has executable or special permission bits',
            ],
            [
                ['name' => './', 'type' => '5', 'mode' => 04755],
                'directory has special permission bits',
            ],
        ];

        foreach ($cases as [$entry, $expectedError]) {
            $deploymentDirectory = $this->createDeploymentFixture();
            $entries = ($entry['type'] ?? null) === '5'
                ? [$entry, ['name' => './manifest.json', 'contents' => '{}']]
                : [$entry];
            $this->writeRawFrontendArchive($deploymentDirectory, $entries);

            $verify = $this->runPhpScriptIn($deploymentDirectory, 'scripts/frontend-release.php', 'verify');

            $this->assertFalse($verify->isSuccessful());
            $this->assertStringContainsString($expectedError, $verify->getErrorOutput());
        }
    }

    public function test_release_verification_bounds_file_size_entry_count_total_size_and_trailing_decompression(): void
    {
        $oversizedDirectory = $this->createDeploymentFixture();
        $this->writeRawFrontendArchive($oversizedDirectory, [
            ['name' => './manifest.json', 'contents' => '{}', 'declared_size' => 16_000_001],
        ]);
        $oversized = $this->runPhpScriptIn($oversizedDirectory, 'scripts/frontend-release.php', 'verify');
        $this->assertFalse($oversized->isSuccessful());
        $this->assertStringContainsString('file exceeds the size limit', $oversized->getErrorOutput());

        $manyEntriesDirectory = $this->createDeploymentFixture();
        $manyEntries = [];

        for ($index = 0; $index <= 1_000; $index++) {
            $manyEntries[] = ['name' => "./assets/file-{$index}.js", 'contents' => ''];
        }

        $this->writeRawFrontendArchive($manyEntriesDirectory, $manyEntries);
        $many = $this->runPhpScriptIn($manyEntriesDirectory, 'scripts/frontend-release.php', 'verify');
        $this->assertFalse($many->isSuccessful());
        $this->assertStringContainsString('contains too many entries', $many->getErrorOutput());

        $aggregateDirectory = $this->createDeploymentFixture();
        $aggregateManifest = json_encode([
            'first' => ['file' => 'assets/first.js'],
            'second' => ['file' => 'assets/second.js'],
        ], JSON_THROW_ON_ERROR);
        $this->writeRawFrontendArchive($aggregateDirectory, [
            ['name' => './manifest.json', 'contents' => $aggregateManifest],
            ['name' => './assets/first.js', 'contents' => str_repeat('a', 12_000_000)],
            ['name' => './assets/second.js', 'contents' => '', 'declared_size' => 12_000_001],
        ]);
        $aggregate = $this->runPhpScriptIn($aggregateDirectory, 'scripts/frontend-release.php', 'verify');
        $this->assertFalse($aggregate->isSuccessful());
        $this->assertStringContainsString('total uncompressed size limit', $aggregate->getErrorOutput());

        $paddingDirectory = $this->createDeploymentFixture();
        $this->writeRawFrontendArchive(
            $paddingDirectory,
            [['name' => './manifest.json', 'contents' => '{}']],
            trailingZeros: 1_048_577,
        );
        $padding = $this->runPhpScriptIn($paddingDirectory, 'scripts/frontend-release.php', 'verify');
        $this->assertFalse($padding->isSuccessful());
        $this->assertStringContainsString('excessive trailing padding', $padding->getErrorOutput());
    }

    public function test_release_rebuild_verification_compares_exact_files_and_bytes(): void
    {
        $deploymentDirectory = $this->createDeploymentFixture();

        $matching = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify-build',
            str_repeat('a', 40),
        );

        $this->assertTrue($matching->isSuccessful(), $matching->getErrorOutput());

        file_put_contents("{$deploymentDirectory}/public/build/manifest.json", "{ }\n");
        $changed = $this->runPhpScriptIn(
            $deploymentDirectory,
            'scripts/frontend-release.php',
            'verify-build',
            str_repeat('a', 40),
        );

        $this->assertFalse($changed->isSuccessful());
        $this->assertStringContainsString(
            'does not match the clean frontend rebuild',
            $changed->getErrorOutput(),
        );
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

    private function createReleaseCommitFixture(bool $includeUnexpectedSource = false): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stocks-deployment-test-'.bin2hex(random_bytes(8));
        $this->temporaryDeploymentDirectories[] = $directory;

        foreach (['app', 'deployment', 'scripts'] as $relativeDirectory) {
            mkdir($directory.DIRECTORY_SEPARATOR.$relativeDirectory, 0777, true);
        }

        copy($this->projectPath('scripts/verify-release-commit.php'), "{$directory}/scripts/verify-release-commit.php");
        file_put_contents("{$directory}/app/Application.php", "<?php\n");
        file_put_contents("{$directory}/deployment/frontend-build.sha256", "old-hash\n");
        file_put_contents("{$directory}/deployment/frontend-build.tar.gz", 'old-archive');
        file_put_contents("{$directory}/deployment/source-commit", str_repeat('a', 40)."\n");
        file_put_contents("{$directory}/deployment/source-manifest.sha256", "old-manifest\n");

        $this->runFixtureGit($directory, 'init');
        $this->runFixtureGit($directory, 'add', '--', '.');
        $this->runFixtureGit($directory, 'commit', '-m', 'Create source release');
        $parent = trim($this->runFixtureGit($directory, 'rev-parse', 'HEAD')->getOutput());

        file_put_contents("{$directory}/deployment/frontend-build.sha256", "new-hash\n");
        file_put_contents("{$directory}/deployment/frontend-build.tar.gz", 'new-archive');
        file_put_contents("{$directory}/deployment/source-commit", "{$parent}\n");
        file_put_contents("{$directory}/deployment/source-manifest.sha256", "new-manifest\n");

        if ($includeUnexpectedSource) {
            file_put_contents("{$directory}/app/Unexpected.php", "<?php\n");
        }

        $this->runFixtureGit($directory, 'add', '--', '.');
        $this->runFixtureGit($directory, 'commit', '-m', 'Build deployment release');

        return str_replace('\\', '/', $directory);
    }

    private function runFixtureGit(string $directory, string ...$arguments): Process
    {
        $process = new Process([
            'git',
            '-c',
            'commit.gpgsign=false',
            '-c',
            'user.name=Deployment Test',
            '-c',
            'user.email=deployment-test@example.com',
            ...$arguments,
        ], $directory);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process;
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

if [ "${1:-}" = "scripts/frontend-release.php" ]; then
    if [ "${2:-}" = "extract-to" ]; then
        tar -xzf deployment/frontend-build.tar.gz -C "$3"
    fi

    exit 0
fi

if [ "${1:-}" != "artisan" ]; then
    exit 0
fi

case "${2:-}" in
    cloudways:pull)
        if [ "${3:-}" = "--check" ]; then
            touch storage/framework/cloudways-api-checked
            exit 0
        fi

        if [ -f storage/framework/fail-cloudways-api-pull ]; then
            exit 1
        fi

        touch storage/framework/cloudways-api-pulled
        ;;
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

    private function createCloudwaysApiPullShellFixture(): string
    {
        $directory = $this->createCloudwaysShellFixture();
        copy($this->projectPath('scripts/pdeploy_cloudways.sh'), "{$directory}/scripts/pdeploy_cloudways.sh");

        $this->writeExecutable("{$directory}/bin/git", <<<'BASH'
#!/usr/bin/env bash
set -e

case "${1:-}" in
    rev-parse)
        exit 1
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

    /**
     * @param array<int, array{
     *     name: string,
     *     contents?: string,
     *     declared_size?: int,
     *     link_name?: string,
     *     mode?: int,
     *     type?: string,
     * }> $entries
     */
    private function writeRawFrontendArchive(
        string $directory,
        array $entries,
        int $trailingZeros = 0,
    ): void {
        $tar = '';

        foreach ($entries as $entry) {
            $contents = $entry['contents'] ?? '';
            $declaredSize = $entry['declared_size'] ?? strlen($contents);
            $type = $entry['type'] ?? '0';
            $mode = $entry['mode'] ?? ($type === '5' ? 0755 : 0644);
            $header = str_pad($entry['name'], 100, "\0")
                .sprintf("%07o\0", $mode)
                .sprintf("%07o\0", 0)
                .sprintf("%07o\0", 0)
                .sprintf("%011o\0", $declaredSize)
                .sprintf("%011o\0", 0)
                .str_repeat(' ', 8)
                .$type
                .str_pad($entry['link_name'] ?? '', 100, "\0")
                ."ustar\0"
                .'00'
                .str_repeat("\0", 32)
                .str_repeat("\0", 32)
                .sprintf("%07o\0", 0)
                .sprintf("%07o\0", 0)
                .str_repeat("\0", 155)
                .str_repeat("\0", 12);
            $this->assertSame(512, strlen($header));
            $checksum = array_sum(array_map('ord', str_split($header)));
            $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
            $tar .= $header.$contents;
            $tar .= str_repeat("\0", (512 - (strlen($contents) % 512)) % 512);
        }

        $tar .= str_repeat("\0", 1_024 + $trailingZeros);
        $archive = gzencode($tar, 9, ZLIB_ENCODING_GZIP);
        $this->assertIsString($archive);
        $archivePath = "{$directory}/deployment/frontend-build.tar.gz";
        file_put_contents($archivePath, $archive);
        $archiveHash = hash_file('sha256', $archivePath);
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
            if ($item->isDir() && ! $item->isLink()) {
                chmod($item->getPathname(), 0777);
                rmdir($item->getPathname());

                continue;
            }

            chmod($item->getPathname(), 0666);
            unlink($item->getPathname());
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
