<?php

namespace App\Console\Commands;

use App\Services\PreviewIsolation;
use App\Services\PreviewReleaseBundle;
use App\Services\ProtectedAdminProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('preview:initialize {--commit= : The verified source commit from the installation plan}')]
#[Description('Initialize only an empty, isolated preview database after a verified first installation')]
class PreviewInitializeCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PreviewIsolation $isolation, ProtectedAdminProvisioner $administrators): int
    {
        if (! $isolation->active() || $isolation->problems() !== []) {
            $this->error('Preview isolation must pass before initialization.');

            return self::FAILURE;
        }
        try {
            $lock = $isolation->lockInstallation();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        try {
            return $this->initializePreview($isolation, $administrators);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function initializePreview(PreviewIsolation $isolation, ProtectedAdminProvisioner $administrators): int
    {
        $marker = $isolation->installationMarker();
        $commit = $this->option('commit');
        if (! is_string($commit) || ! preg_match('/^[a-f0-9]{40}$/D', $commit)
            || ($marker['commit'] ?? null) !== $commit || ($marker['state'] ?? null) !== 'pending'
            || ($marker['prior_database_empty'] ?? null) !== true) {
            $this->error('A matching pending first-install marker is required.');

            return self::FAILURE;
        }
        $markerPath = storage_path('framework/stocks-preview-instance');
        $phase = 'preflight';
        try {
            $credentialsPath = storage_path('app/private/preview-access.json');
            if (! is_file($credentialsPath) || is_link($credentialsPath)) {
                throw new RuntimeException('Preview access credentials are missing.');
            }
            $credentials = json_decode((string) file_get_contents($credentialsPath), true, 16, JSON_THROW_ON_ERROR);
            if (($credentials['access_username'] ?? null) !== 'preview' || ! is_string($credentials['access_password'] ?? null)
                || ! password_verify($credentials['access_password'], (string) config('security.preview.access_password_hash'))) {
                throw new RuntimeException('Preview access credentials do not match the installed configuration.');
            }
            if (Schema::getTables() !== []) {
                throw new RuntimeException('Preview database is not empty.');
            }
            app(PreviewReleaseBundle::class)->verifyInstalled(base_path(), $commit, (string) ($marker['manifest_sha256'] ?? ''));
            $marker['state'] = 'initializing';
            $this->write($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
            $phase = 'migrations';
            $migrator = app('migrator');
            $migrator->getRepository()->createRepository();
            $migrator->run([database_path('migrations')]);

            $phase = 'preview administrator';
            config(['stocks.protected_admin.email' => 'preview-admin@stocks.invalid', 'stocks.protected_admin.first_name' => 'Preview', 'stocks.protected_admin.last_name' => 'Administrator']);
            $administrator = $administrators->provision();
            $password = Str::password(40);
            $administrator->forceFill(['password' => $password, 'password_initialized_at' => now()])->save();
            $credentials['admin_email'] = $administrator->email;
            $credentials['admin_password'] = $password;
            $this->write($credentialsPath, json_encode($credentials, JSON_THROW_ON_ERROR));
            chmod($credentialsPath, 0600);
            $marker['state'] = 'initialized';
            $this->write($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
            $this->info('Preview initialized. It remains in maintenance mode until the remote smoke test and activation gate.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Preview initialization failed at '.$phase.'. Existing data was not replaced; keep maintenance mode and inspect the installation state.');

            return self::FAILURE;
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Cannot save preview initialization state.');
        }
    }
}
