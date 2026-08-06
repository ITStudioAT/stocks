<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeploymentWorkflowTest extends TestCase
{
    public function test_update_launcher_selects_local_and_cloudways_plans(): void
    {
        $local = $this->runPhpScript('scripts/update.php', '--target=local', '--dry-run');
        $cloudways = $this->runPhpScript('scripts/update.php', '--target=cloudways', '--dry-run');

        $this->assertTrue($local->isSuccessful(), $local->getErrorOutput());
        $this->assertStringContainsString('Update target: local', $local->getOutput());
        $this->assertStringContainsString('verified release artifact', $local->getOutput());
        $this->assertTrue($cloudways->isSuccessful(), $cloudways->getErrorOutput());
        $this->assertStringContainsString('guarded Cloudways production deployment', $cloudways->getOutput());
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
        $this->assertContains(
            'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install_powershell_helpers.ps1',
            $composer['scripts']['setup:powershell'],
        );
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
        $this->assertStringContainsString('tar -xzf "$frontend_release_archive"', $deployment);
        $this->assertStringContainsString(
            'php artisan app:update --no-interaction --skip-composer --skip-npm --skip-build',
            $deployment,
        );
        $this->assertStringNotContainsString('--skip-frontend', $deployment);
        $this->assertStringNotContainsString('npm run build', $deployment);
    }

    public function test_dispatcher_allowlists_fetch_and_push_remotes_and_uses_the_repository_root(): void
    {
        $installer = file_get_contents($this->projectPath('scripts/install_powershell_helpers.ps1'));

        $this->assertStringContainsString('ITStudioAT/(?:schooltool|stocks)', $installer);
        $this->assertStringContainsString('remote get-url --all --push origin', $installer);
        $this->assertStringContainsString('Push-Location -LiteralPath `$repositoryRoot', $installer);
        $this->assertStringContainsString('Join-Path `$repositoryRoot \'scripts/gitpush.ps1\'', $installer);
        $this->assertStringNotContainsString('C:\\laravel\\schooltool', $installer);
    }

    public function test_powershell_update_helper_checks_and_updates_composer_and_npm_packages(): void
    {
        $installer = file_get_contents($this->projectPath('scripts/install_powershell_helpers.ps1'));

        $this->assertStringContainsString('function mu', $installer);
        $this->assertMatchesRegularExpression(
            '/composer outdated --direct.*npm outdated.*composer update.*npm update/s',
            $installer,
        );
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

    private function runPhpScript(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, ...$arguments], $this->projectPath());
        $process->run();

        return $process;
    }

    private function projectPath(string $relativePath = ''): string
    {
        $projectDirectory = dirname(__DIR__, 2);

        return $relativePath === ''
            ? $projectDirectory
            : $projectDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
