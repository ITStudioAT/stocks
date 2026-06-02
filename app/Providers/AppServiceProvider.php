<?php

namespace App\Providers;

use App\Services\DeutscheBorseGraphqlClient;
use App\Services\TwelveDataClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeutscheBorseGraphqlClient::class);
        $this->app->singleton(TwelveDataClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
