<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
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
}
