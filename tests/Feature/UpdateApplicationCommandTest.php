<?php

namespace Tests\Feature;

use App\Console\Commands\UpdateApplicationCommand;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdateApplicationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.super_admin.password', null);
    }

    public function test_update_command_can_be_previewed(): void
    {
        $this->artisan('app:update --dry-run')
            ->expectsOutputToContain('Would run: composer install --no-interaction --prefer-dist')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->expectsOutputToContain('Would run: php artisan migrate --force --no-interaction')
            ->expectsOutputToContain('Would run: npm ci --ignore-scripts --no-audit --no-fund --prefer-offline --cache=storage/app/npm-cache --logs-dir=storage/logs/npm')
            ->expectsOutputToContain('Would run: npm run build')
            ->expectsOutputToContain('Would run: php artisan optimize')
            ->expectsOutputToContain('Would run: php artisan queue:restart')
            ->expectsOutputToContain('Would run: php artisan historical-session-prices:dispatch-due')
            ->assertSuccessful();
    }

    public function test_update_command_respects_skip_options(): void
    {
        $this->artisan('app:update --dry-run --skip-composer --skip-npm --skip-build --skip-migrate')
            ->doesntExpectOutputToContain('composer install')
            ->doesntExpectOutputToContain('php artisan migrate')
            ->doesntExpectOutputToContain('npm ci')
            ->doesntExpectOutputToContain('npm run build')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->expectsOutputToContain('Would run: php artisan optimize')
            ->expectsOutputToContain('Would run: php artisan queue:restart')
            ->expectsOutputToContain('Would run: php artisan historical-session-prices:dispatch-due')
            ->assertSuccessful();
    }

    public function test_update_command_has_a_production_composer_mode(): void
    {
        $this->artisan('app:update --dry-run --production')
            ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->assertSuccessful();
    }

    public function test_update_command_installs_missing_required_composer_packages(): void
    {
        $composerJsonPath = base_path('composer.json');
        $originalComposerJson = file_get_contents($composerJsonPath);
        $composerJson = json_decode($originalComposerJson, true);

        unset(
            $composerJson['require']['symfony/http-client'],
            $composerJson['require']['symfony/postmark-mailer'],
        );

        file_put_contents($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('app:update --dry-run')
                ->expectsOutputToContain('Would run: composer require symfony/http-client:^7.4 symfony/postmark-mailer:^7.4 --no-interaction --no-scripts --no-progress')
                ->expectsOutputToContain('Would run: composer install --no-interaction --prefer-dist')
                ->assertSuccessful();
        } finally {
            file_put_contents($composerJsonPath, $originalComposerJson);
        }
    }

    public function test_update_command_stops_when_a_step_fails(): void
    {
        Process::preventStrayProcesses();

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(errorOutput: 'Database connection failed', exitCode: 1),
        ]);

        $this->artisan('app:update')
            ->doesntExpectOutputToContain('Application update complete.')
            ->assertFailed();

        Process::assertRan('composer install --no-interaction --prefer-dist');
        Process::assertRan('php artisan optimize:clear');
        Process::assertDidntRun('php artisan migrate --force --no-interaction');
        Process::assertDidntRun('npm ci --ignore-scripts --no-audit --no-fund --prefer-offline --cache=storage/app/npm-cache --logs-dir=storage/logs/npm');
        Process::assertDidntRun('npm run build');
        Process::assertDidntRun('php artisan optimize');
        Process::assertDidntRun('php artisan queue:restart');
        Process::assertDidntRun('php artisan historical-session-prices:dispatch-due');
    }

    public function test_update_command_uses_project_local_npm_cache_and_log_directories(): void
    {
        $method = new ReflectionMethod(UpdateApplicationCommand::class, 'shellCommandEnvironment');
        $environment = $method->invoke(new UpdateApplicationCommand, 'npm ci --ignore-scripts');

        $this->assertSame(storage_path('app/npm-cache'), $environment['NPM_CONFIG_CACHE']);
        $this->assertSame(storage_path('logs/npm'), $environment['NPM_CONFIG_LOGS_DIR']);
        $this->assertSame('false', $environment['NPM_CONFIG_UPDATE_NOTIFIER']);
        $this->assertSame([], $method->invoke(new UpdateApplicationCommand, 'composer install'));
    }

    public function test_update_command_creates_required_roles_and_protected_admin_user(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        foreach (['super_admin', 'admin', 'user'] as $role) {
            $this->assertDatabaseHas('roles', [
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }

        $protectedUser = User::query()->find(1);

        $this->assertNotNull($protectedUser);
        $this->assertSame('Kron', $protectedUser->last_name);
        $this->assertSame('Günther', $protectedUser->first_name);
        $this->assertSame('kron@naturwelt.at', $protectedUser->email);
        $this->assertNotNull($protectedUser->email_verified_at);
        $this->assertSame(['admin', 'super_admin'], $protectedUser->getRoleNames()->sort()->values()->all());
    }

    public function test_update_command_repairs_protected_admin_user_and_moves_duplicate_email(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        User::factory()->create([
            'id' => 1,
            'last_name' => 'Wrong',
            'first_name' => 'User',
            'email' => 'wrong@example.com',
        ]);

        $duplicateProtectedUser = User::factory()->create([
            'id' => 2,
            'email' => 'kron@naturwelt.at',
        ]);

        Role::findOrCreate('guest', 'web');
        User::query()->find(1)->assignRole('guest');

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $protectedUser = User::query()->find(1);
        $retiredDuplicateUser = $duplicateProtectedUser->fresh();

        $this->assertSame('Kron', $protectedUser->last_name);
        $this->assertSame('Günther', $protectedUser->first_name);
        $this->assertSame('kron@naturwelt.at', $protectedUser->email);
        $this->assertSame(['admin', 'super_admin'], $protectedUser->getRoleNames()->sort()->values()->all());
        $this->assertStringStartsWith('kron.retired.2.', $retiredDuplicateUser->email);
        $this->assertStringEndsWith('@naturwelt.at', $retiredDuplicateUser->email);
    }

    public function test_update_command_uses_the_super_admin_password_from_configuration(): void
    {
        config()->set('services.super_admin.password', 'correct-super-admin-password');

        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        User::factory()->create([
            'id' => 1,
            'email' => 'wrong@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('correct-super-admin-password', User::query()->find(1)->password));
    }

    public function test_update_command_stops_before_migrations_when_pending_migrations_create_the_same_table(): void
    {
        Process::preventStrayProcesses();

        $duplicateMigration = database_path('migrations/2014_10_12_000000_create_users_table.php');

        file_put_contents($duplicateMigration, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP);

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->expectsOutputToContain('Migration preflight failed: multiple pending migrations create the same table.')
                ->expectsOutputToContain('Resolve the duplicate migration before app:update changes Composer or frontend packages.')
                ->expectsOutputToContain('users')
                ->doesntExpectOutputToContain('Application update complete.')
                ->assertFailed();
        } finally {
            unlink($duplicateMigration);
        }

        Process::assertDidntRun('composer install --no-interaction --prefer-dist');
        Process::assertDidntRun('php artisan optimize:clear');
        Process::assertDidntRun('php artisan migrate --force --no-interaction');
    }

    public function test_update_command_retires_the_obsolete_sanctum_migration_before_migration_preflight(): void
    {
        Process::preventStrayProcesses();

        $obsoleteMigration = database_path('migrations/2019_12_14_000001_create_personal_access_tokens_table.php');

        file_put_contents($obsoleteMigration, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
PHP);

        $this->markCurrentMigrationsAsRanExcept([$obsoleteMigration]);

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
        });
        $this->createProtectedAdminTables();

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
            'php artisan historical-session-prices:dispatch-due' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->expectsOutputToContain('Retired already-applied duplicate migration: 2019_12_14_000001_create_personal_access_tokens_table.php')
                ->doesntExpectOutputToContain('Migration preflight failed')
                ->expectsOutputToContain('Application update complete.')
                ->assertSuccessful();

            $this->assertFileExists($obsoleteMigration);
            $this->assertStringContainsString('Retired duplicate migration', file_get_contents($obsoleteMigration));
            $this->assertStringNotContainsString("Schema::create('personal_access_tokens'", file_get_contents($obsoleteMigration));
        } finally {
            if (file_exists($obsoleteMigration)) {
                unlink($obsoleteMigration);
            }
        }

        Process::assertRan('composer install --no-interaction --prefer-dist');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
        Process::assertRan('php artisan optimize');
        Process::assertRan('php artisan queue:restart');
        Process::assertRan('php artisan historical-session-prices:dispatch-due');
    }

    public function test_update_command_retires_legacy_default_migrations_that_create_existing_tables(): void
    {
        Process::preventStrayProcesses();

        $legacyMigrations = [
            database_path('migrations/2014_10_12_000000_create_users_table.php') => 'users',
            database_path('migrations/2014_10_12_100000_create_password_reset_tokens_table.php') => 'password_reset_tokens',
            database_path('migrations/2019_08_19_000000_create_failed_jobs_table.php') => 'failed_jobs',
        ];

        foreach ($legacyMigrations as $migration => $table) {
            file_put_contents($migration, $this->createTableMigrationContent($table));
        }

        $this->markCurrentMigrationsAsRanExcept(array_keys($legacyMigrations));

        $this->createProtectedAdminTables();
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
        });

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
            'php artisan historical-session-prices:dispatch-due' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->expectsOutputToContain('Retired already-applied duplicate migration: 2014_10_12_000000_create_users_table.php')
                ->expectsOutputToContain('Retired already-applied duplicate migration: 2014_10_12_100000_create_password_reset_tokens_table.php')
                ->expectsOutputToContain('Retired already-applied duplicate migration: 2019_08_19_000000_create_failed_jobs_table.php')
                ->doesntExpectOutputToContain('Migration preflight failed')
                ->expectsOutputToContain('Application update complete.')
                ->assertSuccessful();

            foreach ($legacyMigrations as $migration => $table) {
                $this->assertStringContainsString('Retired duplicate migration', file_get_contents($migration));
                $this->assertStringNotContainsString("Schema::create('{$table}'", file_get_contents($migration));
            }
        } finally {
            foreach (array_keys($legacyMigrations) as $migration) {
                if (file_exists($migration)) {
                    unlink($migration);
                }
            }
        }

        Process::assertRan('composer install --no-interaction --prefer-dist');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
        Process::assertRan('php artisan optimize');
        Process::assertRan('php artisan queue:restart');
        Process::assertRan('php artisan historical-session-prices:dispatch-due');
    }

    public function test_update_command_stops_before_migrations_when_a_pending_migration_would_create_an_existing_table(): void
    {
        Process::preventStrayProcesses();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->expectsOutputToContain('Migration preflight failed: pending migrations would create tables that already exist.')
                ->expectsOutputToContain('Resolve the duplicate migration before app:update changes Composer or frontend packages.')
                ->expectsOutputToContain('users')
                ->doesntExpectOutputToContain('Application update complete.')
                ->assertFailed();
        } finally {
            Schema::dropIfExists('users');
        }

        Process::assertDidntRun('composer install --no-interaction --prefer-dist');
        Process::assertDidntRun('php artisan optimize:clear');
        Process::assertDidntRun('php artisan migrate --force --no-interaction');
    }

    private function fakeSuccessfulUpdateProcess(): void
    {
        Process::preventStrayProcesses();

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
            'php artisan historical-session-prices:dispatch-due' => Process::result(),
        ]);
    }

    private function createProtectedAdminTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('last_name')->default('');
            $table->string('first_name')->default('');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    /**
     * @param  array<int, string>  $migrationFiles
     */
    private function markCurrentMigrationsAsRanExcept(array $migrationFiles): void
    {
        $except = collect($migrationFiles)
            ->map(fn (string $migrationFile): string => pathinfo($migrationFile, PATHINFO_FILENAME))
            ->flip();

        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        $migrations = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn (string $migrationFile): string => pathinfo($migrationFile, PATHINFO_FILENAME))
            ->reject(fn (string $migration): bool => $except->has($migration))
            ->values()
            ->map(fn (string $migration): array => [
                'migration' => $migration,
                'batch' => 1,
            ])
            ->all();

        DB::table('migrations')->insert($migrations);
    }

    private function createTableMigrationContent(string $table): string
    {
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table): void {
            \$table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }
}
