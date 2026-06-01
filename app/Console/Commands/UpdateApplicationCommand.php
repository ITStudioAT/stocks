<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('app:update
    {--dry-run : Show the commands without running them}
    {--production : Install Composer dependencies without dev packages}
    {--skip-composer : Do not run composer install}
    {--skip-npm : Do not run npm install}
    {--skip-build : Do not run npm run build}
    {--skip-migrate : Do not run database migrations}')]
#[Description('Update the application after deploying or pulling a new version')]
class UpdateApplicationCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('Updating application');

        foreach ($this->commands() as $label => $command) {
            if ($this->option('dry-run')) {
                $this->line("Would run: {$command}");

                continue;
            }

            $successful = true;

            $this->components->task($label, function () use ($command, &$successful): bool {
                $successful = $this->runShellCommand($command);

                return $successful;
            });

            if (! $successful) {
                return self::FAILURE;
            }
        }

        $this->components->info($this->option('dry-run') ? 'Dry run complete.' : 'Application update complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function commands(): array
    {
        $commands = [];

        if (! $this->option('skip-composer')) {
            $commands['Installing Composer packages'] = $this->option('production')
                ? 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader'
                : 'composer install --no-interaction --prefer-dist';
        }

        $commands['Clearing optimized Laravel files'] = 'php artisan optimize:clear';

        if (! $this->option('skip-migrate')) {
            $commands['Running database migrations'] = 'php artisan migrate --force --no-interaction';
        }

        if (! $this->option('skip-npm')) {
            $commands['Installing npm packages'] = 'npm install --ignore-scripts';
        }

        if (! $this->option('skip-build')) {
            $commands['Building frontend assets'] = 'npm run build';
        }

        return $commands;
    }

    private function runShellCommand(string $command): bool
    {
        $result = Process::path(base_path())
            ->forever()
            ->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->successful()) {
            return true;
        }

        $this->components->error(trim($result->errorOutput()) ?: "Command failed: {$command}");

        return false;
    }
}
