<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

#[Signature('app:update
    {--dry-run : Show the commands without running them}
    {--production : Install Composer dependencies without dev packages}
    {--skip-composer : Do not run composer install}
    {--skip-npm : Do not run npm ci}
    {--skip-build : Do not run npm run build}
    {--skip-migrate : Do not run database migrations}')]
#[Description('Update the application after deploying or pulling a new version')]
class UpdateApplicationCommand extends Command
{
    private const MIGRATION_STEP = 'Running database migrations';

    private const PROTECTED_ADMIN_USER_ID = 1;

    private const PROTECTED_ADMIN_EMAIL = 'kron@naturwelt.at';

    private const PROTECTED_ADMIN_FIRST_NAME = 'Günther';

    private const PROTECTED_ADMIN_LAST_NAME = 'Kron';

    private const PROTECTED_ADMIN_ROLES = ['super_admin', 'admin'];

    private const REQUIRED_ROLES = ['super_admin', 'admin', 'user'];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_COMPOSER_PACKAGES = [
        'symfony/http-client' => '^7.4',
        'symfony/postmark-mailer' => '^7.4',
    ];

    public function handle(): int
    {
        $this->components->info('Updating application');

        if (! $this->option('dry-run') && ! $this->option('skip-migrate')) {
            if (! $this->retireAlreadyAppliedDuplicateCreateMigrations()) {
                return self::FAILURE;
            }

            if (! $this->ensureMigrationsCanRun()) {
                return self::FAILURE;
            }
        }

        $protectedAdminUserVerified = false;

        foreach ($this->commands() as $label => $command) {
            if ($this->option('dry-run')) {
                $this->line("Would run: {$command}");

                continue;
            }

            $successful = true;

            $this->components->task($label, function () use ($label, $command, &$successful): bool {
                if ($label === 'Installing npm packages' && ! $this->prepareNpmInstall()) {
                    $successful = false;

                    return false;
                }

                $successful = $this->runShellCommand($command);

                return $successful;
            });

            if (! $successful) {
                return self::FAILURE;
            }

            if ($label === self::MIGRATION_STEP) {
                if (! $this->ensureProtectedAdminUser()) {
                    return self::FAILURE;
                }

                $protectedAdminUserVerified = true;
            }
        }

        if (! $this->option('dry-run') && ! $protectedAdminUserVerified && ! $this->ensureProtectedAdminUser()) {
            return self::FAILURE;
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
            $missingRequiredComposerPackages = $this->missingRequiredComposerPackages();

            if ($missingRequiredComposerPackages !== []) {
                $commands['Installing required Composer packages'] = 'composer require '
                    .implode(' ', $missingRequiredComposerPackages)
                    .' --no-interaction --no-scripts --no-progress';
            }

            $commands['Installing Composer packages'] = $this->option('production')
                ? 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader'
                : 'composer install --no-interaction --prefer-dist';
        }

        $commands['Clearing optimized Laravel files'] = 'php artisan optimize:clear';

        if (! $this->option('skip-migrate')) {
            $commands[self::MIGRATION_STEP] = 'php artisan migrate --force --no-interaction';
        }

        if (! $this->option('skip-npm')) {
            $commands['Installing npm packages'] = 'npm ci --ignore-scripts --no-audit --no-fund --prefer-offline --cache=storage/app/npm-cache --logs-dir=storage/logs/npm';
        }

        if (! $this->option('skip-build')) {
            $commands['Building frontend assets'] = 'npm run build';
        }

        return $commands;
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredComposerPackages(): array
    {
        $composerJson = json_decode(File::get(base_path('composer.json')), true);
        $requiredPackages = is_array($composerJson)
            ? ($composerJson['require'] ?? [])
            : [];

        if (! is_array($requiredPackages)) {
            $requiredPackages = [];
        }

        return collect(self::REQUIRED_COMPOSER_PACKAGES)
            ->reject(fn (string $constraint, string $package): bool => array_key_exists($package, $requiredPackages))
            ->map(fn (string $constraint, string $package): string => "{$package}:{$constraint}")
            ->values()
            ->all();
    }

    private function prepareNpmInstall(): bool
    {
        foreach ([
            storage_path('app/npm-cache'),
            storage_path('logs/npm'),
        ] as $directory) {
            File::ensureDirectoryExists($directory);

            if (! File::isDirectory($directory)) {
                $this->components->error("Could not create npm working directory: {$directory}");

                return false;
            }
        }

        $nodeModulesPath = base_path('node_modules');

        if (File::isDirectory($nodeModulesPath) && ! File::deleteDirectory($nodeModulesPath)) {
            $this->components->error('Could not remove the existing node_modules directory before npm ci.');

            return false;
        }

        return true;
    }

    private function ensureProtectedAdminUser(): bool
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            DB::transaction(function (): void {
                foreach (self::REQUIRED_ROLES as $role) {
                    Role::findOrCreate($role, 'web');
                }

                $duplicateProtectedUser = User::query()
                    ->where('email', self::PROTECTED_ADMIN_EMAIL)
                    ->whereKeyNot(self::PROTECTED_ADMIN_USER_ID)
                    ->first();

                if ($duplicateProtectedUser) {
                    $duplicateProtectedUser->forceFill([
                        'email' => $this->retiredProtectedAdminEmail($duplicateProtectedUser),
                    ])->save();
                }

                $user = User::query()->find(self::PROTECTED_ADMIN_USER_ID) ?? new User;
                $user->id = self::PROTECTED_ADMIN_USER_ID;

                $attributes = [
                    'last_name' => self::PROTECTED_ADMIN_LAST_NAME,
                    'first_name' => self::PROTECTED_ADMIN_FIRST_NAME,
                    'email' => self::PROTECTED_ADMIN_EMAIL,
                    'email_verified_at' => now(),
                ];

                $protectedAdminPassword = $this->protectedAdminPassword();

                if ($protectedAdminPassword) {
                    $attributes['password'] = $protectedAdminPassword;
                }

                if (! $protectedAdminPassword && (! $user->exists || blank($user->password))) {
                    $attributes['password'] = Str::password(32);
                }

                $user->forceFill($attributes)->save();
                $user->syncRoles(self::PROTECTED_ADMIN_ROLES);
            });

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->components->info('Verified roles and protected admin user.');

            return true;
        } catch (Throwable $exception) {
            $this->components->error("Could not verify protected admin user: {$exception->getMessage()}");

            return false;
        }
    }

    private function protectedAdminPassword(): ?string
    {
        $password = config('services.super_admin.password');

        if (! is_string($password) || blank($password)) {
            return null;
        }

        return $password;
    }

    private function retiredProtectedAdminEmail(User $user): string
    {
        $baseEmail = "kron.retired.{$user->id}.".now()->format('YmdHis');
        $email = "{$baseEmail}@naturwelt.at";
        $attempt = 1;

        while (User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            $email = "{$baseEmail}.{$attempt}@naturwelt.at";
            $attempt++;
        }

        return $email;
    }

    private function retireAlreadyAppliedDuplicateCreateMigrations(): bool
    {
        $tableCreateCounts = $this->tableCreateCounts();

        foreach ($this->pendingMigrationFiles() as $migrationFile) {
            $createdTables = $this->createdTablesInMigration($migrationFile);

            if ($createdTables === []) {
                continue;
            }

            $createsOnlyDuplicateTables = collect($createdTables)
                ->every(fn (string $table): bool => ($tableCreateCounts[$table] ?? 0) > 1);

            if (! $createsOnlyDuplicateTables) {
                continue;
            }

            $allTablesAlreadyExist = collect($createdTables)
                ->every(fn (string $table): bool => Schema::hasTable($table));

            if (! $allTablesAlreadyExist) {
                continue;
            }

            $migration = basename($migrationFile);

            if (File::put($migrationFile, $this->retiredCreateMigrationNoopContent($migration, $createdTables)) === false) {
                $this->components->error("Could not retire already-applied duplicate migration: {$migration}");

                return false;
            }

            $this->components->info("Retired already-applied duplicate migration: {$migration}");
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function tableCreateCounts(): array
    {
        $tableCreateCounts = [];

        foreach ($this->migrationFiles() as $migrationFile) {
            foreach ($this->createdTablesInMigration($migrationFile) as $table) {
                $tableCreateCounts[$table] = ($tableCreateCounts[$table] ?? 0) + 1;
            }
        }

        return $tableCreateCounts;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function retiredCreateMigrationNoopContent(string $migration, array $tables): string
    {
        $tableList = implode(', ', $tables);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;

/*
|--------------------------------------------------------------------------
| Retired duplicate migration
|--------------------------------------------------------------------------
|
| app:update replaced {$migration} with this no-op because it only creates
| tables that already exist and are also created by another migration:
| {$tableList}
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
        $migrationFiles = $this->migrationFiles();

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
    private function migrationFiles(): array
    {
        $migrationFiles = File::glob(database_path('migrations/*.php')) ?: [];
        sort($migrationFiles);

        return $migrationFiles;
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
            ->env($this->shellCommandEnvironment($command))
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

    /**
     * @return array<string, string>
     */
    private function shellCommandEnvironment(string $command): array
    {
        if (! str_starts_with($command, 'npm ')) {
            return [];
        }

        return [
            'NPM_CONFIG_CACHE' => storage_path('app/npm-cache'),
            'NPM_CONFIG_LOGS_DIR' => storage_path('logs/npm'),
            'NPM_CONFIG_UPDATE_NOTIFIER' => 'false',
        ];
    }
}
