<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->usesProtectedDatabase());
    }

    private function usesProtectedDatabase(): bool
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        return ($connection['driver'] ?? null) === 'mysql'
            && ($connection['database'] ?? null) === 'stocks';
    }
}
