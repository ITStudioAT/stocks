<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpdateApplicationCommandTest extends TestCase
{
    public function test_update_command_can_be_previewed(): void
    {
        $this->artisan('app:update --dry-run')
            ->expectsOutputToContain('Would run: composer install --no-interaction --prefer-dist')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->expectsOutputToContain('Would run: php artisan migrate --force --no-interaction')
            ->expectsOutputToContain('Would run: npm install --ignore-scripts')
            ->expectsOutputToContain('Would run: npm run build')
            ->assertSuccessful();
    }

    public function test_update_command_respects_skip_options(): void
    {
        $this->artisan('app:update --dry-run --skip-composer --skip-npm --skip-build --skip-migrate')
            ->doesntExpectOutputToContain('composer install')
            ->doesntExpectOutputToContain('php artisan migrate')
            ->doesntExpectOutputToContain('npm install')
            ->doesntExpectOutputToContain('npm run build')
            ->expectsOutputToContain('Would run: php artisan optimize:clear')
            ->assertSuccessful();
    }

    public function test_update_command_has_a_production_composer_mode(): void
    {
        $this->artisan('app:update --dry-run --production')
            ->expectsOutputToContain('Would run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
            ->assertSuccessful();
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
        Process::assertDidntRun('npm install --ignore-scripts');
        Process::assertDidntRun('npm run build');
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

    public function test_update_command_removes_the_obsolete_sanctum_migration_before_migration_preflight(): void
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

        Process::fake([
            'composer install --no-interaction --prefer-dist' => Process::result(),
            'php artisan optimize:clear' => Process::result(),
            'php artisan migrate --force --no-interaction' => Process::result(),
        ]);

        try {
            $this->artisan('app:update --skip-npm --skip-build')
                ->expectsOutputToContain('Removed obsolete duplicate migration: 2019_12_14_000001_create_personal_access_tokens_table.php')
                ->doesntExpectOutputToContain('Migration preflight failed')
                ->expectsOutputToContain('Application update complete.')
                ->assertSuccessful();

            $this->assertFileDoesNotExist($obsoleteMigration);
        } finally {
            if (file_exists($obsoleteMigration)) {
                unlink($obsoleteMigration);
            }
        }

        Process::assertRan('composer install --no-interaction --prefer-dist');
        Process::assertRan('php artisan optimize:clear');
        Process::assertRan('php artisan migrate --force --no-interaction');
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
}
