<?php

namespace App\Console\Commands;

use App\Services\LocalMarketDataDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

#[Signature('app:prepare-local-development')]
#[Description('Apply safe pending migrations to the local Stocks database')]
class PrepareLocalDevelopment extends Command
{
    public function handle(): int
    {
        $name = config('database.default');
        $connection = config("database.connections.{$name}");
        if (! app()->environment('local') || ! is_array($connection)
            || ! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ($connection['database'] ?? null) !== 'stocks'
            || ! empty($connection['url']) || ! empty($connection['unix_socket'])
            || ! empty($connection['read']) || ! empty($connection['write'])) {
            $this->error('Development migrations require APP_ENV=local and the local MySQL database stocks, without a URL or connection overrides.');

            return self::FAILURE;
        }

        try {
            app(LocalMarketDataDatabase::class)->assertLocal();
        } catch (\Throwable $exception) {
            $this->error('Could not verify the local database server: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (Artisan::call('migrate', ['--pretend' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            $this->error('Could not inspect pending migrations. Development processes were not started.');
            $this->line(Artisan::output());

            return self::FAILURE;
        }

        $plan = Artisan::output();
        if (self::containsUnsafeSql($plan)) {
            $this->error('Pending migrations contain a destructive or data-changing operation. Review them before starting.');

            return self::FAILURE;
        }

        $this->info('Applying pending migrations to the local Stocks database.');

        return Artisan::call('migrate', ['--no-interaction' => true], $this->output);
    }

    public static function containsUnsafeSql(string $plan): bool
    {
        return (bool) preg_match('/(?:^|\n)\s*(?:⇂\s*)?(?:drop\s+(?:table|database)|truncate\b|delete\s+from\b|update\s+[`"a-z_]|(?:insert|replace)\s+into\b|alter\s+table\s+[^\n]+\s+(?:drop\s+(?:column|foreign|constraint)|modify\b|change\b|rename\b))/i', $plan);
    }
}
