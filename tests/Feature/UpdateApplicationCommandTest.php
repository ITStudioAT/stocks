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

        config()->set([
            'stocks.protected_admin.email' => 'protected@example.com',
            'stocks.protected_admin.first_name' => 'Protected',
            'stocks.protected_admin.last_name' => 'Administrator',
        ]);
    }

    public function test_update_command_can_be_previewed(): void
    {
        $this->artisan('app:update --dry-run')
            ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->expectsOutputToContain('Would run: php artisan migrate --force --no-interaction')
            ->expectsOutputToContain('Would run: node scripts/dev-stop-stale-vite.mjs --strict')
            ->expectsOutputToContain('Would run: npm ci --ignore-scripts --no-fund --prefer-offline --cache=storage/app/npm-cache --logs-dir=storage/logs/npm')
            ->expectsOutputToContain('Would run: npm run build')
            ->expectsOutputToContain('Would run: php artisan optimize')
            ->expectsOutputToContain('Would run: php artisan queue:restart')
            ->assertSuccessful();
    }

    public function test_update_command_respects_skip_options(): void
    {
        $this->artisan('app:update --dry-run --skip-composer --skip-npm --skip-build --skip-migrate')
            ->doesntExpectOutputToContain('composer install')
            ->doesntExpectOutputToContain('php artisan migrate')
            ->doesntExpectOutputToContain('node scripts/dev-stop-stale-vite.mjs')
            ->doesntExpectOutputToContain('npm ci')
            ->doesntExpectOutputToContain('npm run build')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->expectsOutputToContain('Would run: php artisan optimize')
            ->expectsOutputToContain('Would run: php artisan queue:restart')
            ->assertSuccessful();
    }

    public function test_update_command_has_a_production_composer_mode(): void
    {
        $this->artisan('app:update --dry-run --production')
            ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->expectsOutputToContain('Would run: php artisan security:preflight --no-interaction')
            ->expectsOutputToContain('Would run: php artisan security:redact-eodhd-errors --no-interaction')
            ->assertSuccessful();
    }

    public function test_update_command_uses_production_composer_mode_by_default(): void
    {
        $this->artisan('app:update --dry-run')
            ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->assertSuccessful();
    }

    public function test_update_command_can_force_dev_composer_packages(): void
    {
        $this->artisan('app:update --dry-run --dev')
            ->expectsOutputToContain('Would run: composer install --no-interaction --prefer-dist')
            ->doesntExpectOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
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
                ->expectsOutputToContain('Would run: composer require symfony/http-client:^8.0 symfony/postmark-mailer:^8.0 --no-interaction --no-scripts --no-progress')
                ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
                ->assertSuccessful();
        } finally {
            file_put_contents($composerJsonPath, $originalComposerJson);
        }
    }

    public function test_update_command_stops_when_a_step_fails(): void
    {
        Process::preventStrayProcesses();

        Process::fake([
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
            'php artisan optimize:clear' => Process::result(errorOutput: 'Database connection failed', exitCode: 1),
        ]);

        $this->artisan('app:update')
            ->doesntExpectOutputToContain('Application update complete.')
            ->assertFailed();

        Process::assertRan('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        Process::assertRan('php artisan optimize:clear');
        Process::assertDidntRun('php artisan migrate --force --no-interaction');
        Process::assertDidntRun('node scripts/dev-stop-stale-vite.mjs --strict');
        Process::assertDidntRun('npm ci --ignore-scripts --no-fund --prefer-offline --cache=storage/app/npm-cache --logs-dir=storage/logs/npm');
        Process::assertDidntRun('npm run build');
        Process::assertDidntRun('php artisan optimize');
        Process::assertDidntRun('php artisan queue:restart');
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

        $protectedUser = User::query()->where('email', 'protected@example.com')->first();

        $this->assertNotNull($protectedUser);
        $this->assertSame('Administrator', $protectedUser->last_name);
        $this->assertSame('Protected', $protectedUser->first_name);
        $this->assertSame('protected@example.com', $protectedUser->email);
        $this->assertNotNull($protectedUser->email_verified_at);
        $this->assertTrue($protectedUser->is_protected);
        $this->assertNull($protectedUser->password_initialized_at);
        $this->assertSame(['admin', 'super_admin'], $protectedUser->getRoleNames()->sort()->values()->all());
    }

    public function test_update_command_does_not_elevate_unrelated_id_one_and_safely_elevates_the_configured_identity(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        $unrelatedUser = User::factory()->create([
            'id' => 1,
            'email' => 'unrelated@example.com',
        ]);
        $configuredUser = User::factory()->create([
            'last_name' => 'Existing',
            'first_name' => 'Administrator',
            'email' => 'protected@example.com',
            'password' => Hash::make('Known-Ordinary-Password-123!'),
            'auth_revision' => 7,
        ]);
        DB::table('admin_login_codes')->insert([
            'email' => 'protected@example.com',
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $unrelatedUser->refresh();
        $configuredUser->refresh();

        $this->assertFalse($unrelatedUser->is_protected);
        $this->assertSame([], $unrelatedUser->getRoleNames()->all());
        $this->assertTrue($configuredUser->is_protected);
        $this->assertFalse(Hash::check('Known-Ordinary-Password-123!', $configuredUser->password));
        $this->assertNull($configuredUser->password_initialized_at);
        $this->assertSame(8, $configuredUser->auth_revision);
        $this->assertSame(0, DB::table('admin_login_codes')->where('email', 'protected@example.com')->count());
        $this->assertSame(['admin', 'super_admin'], $configuredUser->getRoleNames()->sort()->values()->all());
    }

    public function test_update_command_preserves_an_existing_protected_admin_identity_and_password(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        $existingProtectedUser = User::factory()->create([
            'id' => 47,
            'last_name' => 'Existing',
            'first_name' => 'Administrator',
            'email' => 'existing@example.com',
            'is_protected' => true,
        ]);
        $existingPassword = $existingProtectedUser->password;

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $protectedUser = User::query()->findOrFail(47);

        $this->assertSame('Existing', $protectedUser->last_name);
        $this->assertSame('Administrator', $protectedUser->first_name);
        $this->assertSame('existing@example.com', $protectedUser->email);
        $this->assertSame($existingPassword, $protectedUser->password);
        $this->assertTrue($protectedUser->is_protected);
        $this->assertSame(['admin', 'super_admin'], $protectedUser->getRoleNames()->sort()->values()->all());
    }

    public function test_update_command_preserves_a_matching_existing_super_admin_password_during_upgrade(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        Role::findOrCreate('super_admin', 'web');
        $existingSuperAdmin = User::factory()->create([
            'email' => 'protected@example.com',
            'password' => Hash::make('Existing-Super-Admin-Password-123!'),
            'auth_revision' => 5,
        ]);
        $existingSuperAdmin->assignRole('super_admin');
        DB::table('admin_login_codes')->insert([
            'email' => $existingSuperAdmin->email,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->assertSuccessful();

        $existingSuperAdmin->refresh();

        $this->assertTrue(Hash::check('Existing-Super-Admin-Password-123!', $existingSuperAdmin->password));
        $this->assertNotNull($existingSuperAdmin->password_initialized_at);
        $this->assertTrue($existingSuperAdmin->is_protected);
        $this->assertSame(6, $existingSuperAdmin->auth_revision);
        $this->assertFalse(DB::table('admin_login_codes')->where('email', $existingSuperAdmin->email)->exists());
        $this->assertSame(['admin', 'super_admin'], $existingSuperAdmin->getRoleNames()->sort()->values()->all());
    }

    public function test_update_command_ignores_the_legacy_sa_pw_when_bootstrapping_the_protected_admin(): void
    {
        config()->set('services.super_admin.password', 'legacy-master-password');

        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $protectedAdmin = User::query()->where('email', 'protected@example.com')->firstOrFail();

        $this->assertNotSame('', $protectedAdmin->password);
        $this->assertFalse(Hash::check('legacy-master-password', $protectedAdmin->password));
    }

    public function test_update_command_does_not_overwrite_an_existing_protected_admin_password(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        User::factory()->create([
            'id' => 47,
            'email' => 'existing@example.com',
            'password' => Hash::make('old-password'),
            'is_protected' => true,
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $protectedAdmin = User::query()->findOrFail(47);

        $this->assertTrue(Hash::check('old-password', $protectedAdmin->password));
    }

    public function test_update_command_repairs_a_blank_password_with_an_unpredictable_value(): void
    {
        config()->set('services.super_admin.password', 'legacy-master-password');

        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();
        DB::table('users')->insert([
            'id' => 1,
            'last_name' => 'Existing',
            'first_name' => 'Admin',
            'email' => 'existing@example.com',
            'password' => '',
            'auth_revision' => 1,
            'is_protected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Verified roles and protected admin user.')
            ->expectsOutputToContain('Application update complete.')
            ->assertSuccessful();

        $protectedAdmin = User::query()->findOrFail(1);

        $this->assertNotSame('', $protectedAdmin->password);
        $this->assertFalse(Hash::check('legacy-master-password', $protectedAdmin->password));
        $this->assertNull($protectedAdmin->password_initialized_at);
        $this->assertSame(2, $protectedAdmin->auth_revision);
    }

    public function test_update_command_fails_closed_when_multiple_protected_accounts_exist(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        User::factory()->count(2)->create([
            'is_protected' => true,
        ]);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Multiple protected administrator accounts exist. Refusing to choose one.')
            ->doesntExpectOutputToContain('Application update complete.')
            ->assertFailed();
    }

    public function test_update_command_fails_closed_when_configured_email_matches_multiple_normalized_users(): void
    {
        $this->markCurrentMigrationsAsRanExcept([]);
        $this->createProtectedAdminTables();

        User::factory()->create(['email' => 'protected@example.com']);
        User::factory()->create(['email' => 'Protected@Example.com']);

        $this->fakeSuccessfulUpdateProcess();

        $this->artisan('app:update --skip-npm --skip-build')
            ->expectsOutputToContain('Multiple users match SUPER_ADMIN_EMAIL after normalization. Refusing to elevate any account.')
            ->doesntExpectOutputToContain('Application update complete.')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_protected', true)->count());
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
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
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

        Process::assertDidntRun('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
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
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
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

        Process::assertRan('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
        Process::assertRan('php artisan optimize');
        Process::assertRan('php artisan queue:restart');
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
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
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

        Process::assertRan('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
        Process::assertRan('php artisan optimize');
        Process::assertRan('php artisan queue:restart');
    }

    public function test_update_command_stops_before_migrations_when_a_pending_migration_would_create_an_existing_table(): void
    {
        Process::preventStrayProcesses();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        Process::fake([
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
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

        Process::assertDidntRun('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        Process::assertDidntRun('php artisan optimize:clear');
        Process::assertDidntRun('php artisan migrate --force --no-interaction');
    }

    public function test_update_command_allows_guarded_pending_migrations_that_reference_existing_tables(): void
    {
        Process::preventStrayProcesses();

        $guardedMigration = database_path('migrations/2014_10_12_000000_create_users_table.php');

        file_put_contents($guardedMigration, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
            });
        }
    }
};
PHP);

        $this->markCurrentMigrationsAsRanExcept([$guardedMigration]);
        $this->createProtectedAdminTables();

        Process::fake([
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->doesntExpectOutputToContain('Migration preflight failed')
                ->expectsOutputToContain('Application update complete.')
                ->assertSuccessful();
        } finally {
            if (file_exists($guardedMigration)) {
                unlink($guardedMigration);
            }
        }

        Process::assertRan('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
        Process::assertRan('php artisan optimize');
        Process::assertRan('php artisan queue:restart');
    }

    private function fakeSuccessfulUpdateProcess(): void
    {
        Process::preventStrayProcesses();

        Process::fake([
            'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
            'php artisan optimize' => Process::result(),
            'php artisan queue:restart' => Process::result(),
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
            $table->unsignedBigInteger('auth_revision')->default(1);
            $table->timestamp('password_initialized_at')->nullable();
            $table->boolean('is_protected')->default(false);
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

        Schema::create('admin_login_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
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
