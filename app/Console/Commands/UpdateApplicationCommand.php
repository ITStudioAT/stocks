<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

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
    private const MIGRATION_STEP = 'Running database migrations';

    private const OBSOLETE_DUPLICATE_MIGRATIONS = [
        [
            'obsolete' => '2019_12_14_000001_create_personal_access_tokens_table.php',
            'replacement' => '2026_05_23_153233_create_personal_access_tokens_table.php',
        ],
    ];

    public function handle(): int
    {
        $this->components->info('Updating application');

        if (! $this->option('dry-run') && ! $this->option('skip-migrate')) {
            if (! $this->retireObsoleteDuplicateMigrations()) {
                return self::FAILURE;
            }

            if (! $this->ensureMigrationsCanRun()) {
                return self::FAILURE;
            }
        }

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
            $commands[self::MIGRATION_STEP] = 'php artisan migrate --force --no-interaction';
        }

        if (! $this->option('skip-npm')) {
            $commands['Installing npm packages'] = 'npm install --ignore-scripts';
        }

        if (! $this->option('skip-build')) {
            $commands['Building frontend assets'] = 'npm run build';
        }

        return $commands;
    }

    private function retireObsoleteDuplicateMigrations(): bool
    {
        foreach (self::OBSOLETE_DUPLICATE_MIGRATIONS as $migration) {
            $obsoletePath = database_path("migrations/{$migration['obsolete']}");
            $replacementPath = database_path("migrations/{$migration['replacement']}");

            if (! File::exists($obsoletePath) || ! File::exists($replacementPath)) {
                continue;
            }

            if (File::put($obsoletePath, $this->obsoleteDuplicateMigrationNoopContent($migration['obsolete'])) === false) {
                $this->components->error("Could not retire obsolete duplicate migration: {$migration['obsolete']}");

                return false;
            }

            $this->components->info("Retired obsolete duplicate migration: {$migration['obsolete']}");
        }

        return true;
    }

    private function obsoleteDuplicateMigrationNoopContent(string $migration): string
    {
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;

/*
|--------------------------------------------------------------------------
| Retired duplicate migration
|--------------------------------------------------------------------------
|
| app:update replaces {$migration} with this no-op because this project
| keeps the real personal_access_tokens schema in the 2026 migration.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
PHP;
    }

    private function ensureMigrationsCanRun(): bool
    {
        $pendingCreateTables = $this->pendingCreateTablesByMigration();
        $duplicateTables = array_filter(
            $pendingCreateTables,
            fn (array $migrations): bool => count($migrations) > 1,
        );

        if ($duplicateTables !== []) {
            $this->reportUnsafeMigrations('multiple pending migrations create the same table', $duplicateTables);

            return false;
        }

        $existingTables = [];

        foreach ($pendingCreateTables as $table => $migrations) {
            if (Schema::hasTable($table)) {
                $existingTables[$table] = $migrations;
            }
        }

        if ($existingTables !== []) {
            $this->reportUnsafeMigrations('pending migrations would create tables that already exist', $existingTables);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pendingCreateTablesByMigration(): array
    {
        $tables = [];

        foreach ($this->pendingMigrationFiles() as $migrationFile) {
            foreach ($this->createdTablesInMigration($migrationFile) as $table) {
                $tables[$table][] = basename($migrationFile);
            }
        }

        return $tables;
    }

    /**
     * @return array<int, string>
     */
    private function pendingMigrationFiles(): array
    {
        $migrationFiles = File::glob(database_path('migrations/*.php')) ?: [];
        sort($migrationFiles);

        $migrationRepositoryTable = config('database.migrations.table', 'migrations');

        if (! Schema::hasTable($migrationRepositoryTable)) {
            return $migrationFiles;
        }

        $ranMigrations = DB::table($migrationRepositoryTable)
            ->pluck('migration')
            ->flip();

        return array_values(array_filter(
            $migrationFiles,
            fn (string $migrationFile): bool => ! $ranMigrations->has(pathinfo($migrationFile, PATHINFO_FILENAME)),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function createdTablesInMigration(string $migrationFile): array
    {
        preg_match_all(
            '/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            File::get($migrationFile),
            $matches,
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  array<string, array<int, string>>  $tables
     */
    private function reportUnsafeMigrations(string $reason, array $tables): void
    {
        $this->components->error("Migration preflight failed: {$reason}.");
        $this->line('Resolve the duplicate migration before app:update changes Composer or frontend packages.');

        foreach ($tables as $table => $migrations) {
            $this->line(" - {$table}: ".implode(', ', $migrations));
        }
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
