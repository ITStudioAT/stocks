<?php

use App\Http\Middleware\EnsureAuthenticationRevisionIsCurrent;
use App\Http\Middleware\EnsurePreviewIsolation;
use App\Http\Middleware\EnsureTrustedHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->prepend(EnsureTrustedHost::class);
        $middleware->prepend(EnsurePreviewIsolation::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'auth.session' => EnsureAuthenticationRevisionIsCurrent::class,
            'role' => RoleMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
