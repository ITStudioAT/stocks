<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PreviewDeploymentGuardTest extends TestCase
{
    #[DataProvider('previewRoles')]
    public function test_live_update_stops_before_dependencies_or_maintenance(string $role): void
    {
        $directory = sys_get_temp_dir().'/stocks-preview-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/scripts', 0700, true);
        mkdir($directory.'/storage/framework', 0700, true);
        mkdir($directory.'/bootstrap/cache', 0700, true);
        try {
            foreach (['preview-guard.php', 'update.php'] as $script) {
                copy(dirname(__DIR__, 2).'/scripts/'.$script, $directory.'/scripts/'.$script);
            }
            $environment = ['APP_ENV' => 'local', 'STOCKS_PREVIEW' => 'false'];
            if ($role === 'marker') {
                file_put_contents($directory.'/storage/framework/stocks-preview-instance', '200');
            } elseif ($role === 'interrupted-swap') {
                mkdir($directory.'/.stocks-preview-private', 0700);
                file_put_contents($directory.'/.stocks-preview-private/swap.json', '{}');
            } elseif ($role === 'cache') {
                file_put_contents($directory.'/bootstrap/cache/config.php', '<?php return ["security" => ["preview" => ["enabled" => true]]];');
            } elseif ($role === 'environment') {
                $environment['STOCKS_PREVIEW'] = 'true';
            } else {
                file_put_contents($directory.'/.env', "APP_ENV=preview\nSECRET=do-not-print\n");
            }
            $process = new Process([PHP_BINARY, 'scripts/update.php', '--target=cloudways', '--prepare'], $directory, $environment);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('live deployment workflow is disabled', $process->getErrorOutput());
            $this->assertStringNotContainsString('do-not-print', $process->getOutput().$process->getErrorOutput());
            $this->assertDirectoryDoesNotExist($directory.'/vendor');
            $this->assertFileDoesNotExist($directory.'/storage/framework/down');
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public static function previewRoles(): array
    {
        return [['marker'], ['interrupted-swap'], ['cache'], ['environment'], ['dotenv']];
    }

    public function test_both_cloudways_shell_entrypoints_guard_before_any_deployment_action(): void
    {
        foreach (['deploy_cloudways.sh', 'pdeploy_cloudways.sh'] as $script) {
            $source = file_get_contents(dirname(__DIR__, 2).'/scripts/'.$script);
            $this->assertStringContainsString("cd \"\$project_directory\"\n\nphp scripts/preview-guard.php", str_replace("\r\n", "\n", $source));
        }
    }
}
