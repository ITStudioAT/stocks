<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PreviewIsolation;
use App\Services\PreviewReleaseBundle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('preview:activate {--commit= : Verified installed source commit} {--web-php= : Independently verified PHP-FPM version}')]
#[Description('Open only an initialized private preview after its first-install gates pass')]
class PreviewActivateCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PreviewIsolation $isolation): int
    {
        try {
            $lock = $isolation->lockInstallation();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        try {
            return $this->activate($isolation);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function activate(PreviewIsolation $isolation): int
    {
        $marker = $isolation->installationMarker();
        $commit = $this->option('commit');
        $webPhp = $this->option('web-php');
        if (! $isolation->active() || $isolation->problems() !== [] || ! is_string($commit)
            || ! preg_match('/^[a-f0-9]{40}$/D', $commit) || ($marker['commit'] ?? null) !== $commit
            || ($marker['state'] ?? null) !== 'initialized' || ! is_string($webPhp)
            || ! preg_match('/^8\.[4-9]\.\d+$/D', $webPhp) || version_compare($webPhp, '8.4.1', '<')) {
            $this->error('Preview activation identity, initialization or web PHP gate is incomplete.');

            return self::FAILURE;
        }
        $migrator = app('migrator');
        try {
            app(PreviewReleaseBundle::class)->verifyInstalled(base_path(), $commit, (string) ($marker['manifest_sha256'] ?? ''));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()) !== []
            || ! User::query()->where('email', 'preview-admin@stocks.invalid')->where('is_protected', true)->whereNotNull('password_initialized_at')->exists()) {
            $this->error('Preview schema or its own administrator is incomplete.');

            return self::FAILURE;
        }
        $downPath = storage_path('framework/down');
        if (! is_file($downPath) || is_link($downPath)
            || (json_decode((string) file_get_contents($downPath), true)['stocks_preview_commit'] ?? null) !== $commit) {
            $this->error('Refusing to remove an unrelated maintenance state.');

            return self::FAILURE;
        }
        $marker['state'] = 'active';
        $marker['web_php'] = $webPhp;
        $contents = json_encode($marker, JSON_THROW_ON_ERROR);
        if (file_put_contents(storage_path('framework/stocks-preview-instance'), $contents, LOCK_EX) !== strlen($contents) || ! unlink($downPath)) {
            $this->error('Could not finish preview activation; inspect the maintenance state.');

            return self::FAILURE;
        }
        $this->info('Private preview activated. Verify HTTPS, Basic Auth, login and disabled integrations before using it.');

        return self::SUCCESS;
    }
}
