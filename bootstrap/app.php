<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $proxySetting = env('TRUST_PROXIES', '');
        if ($proxySetting && $proxySetting !== 'none') {
            $middleware->trustProxies(
                at: $proxySetting === '*' ? '*' : explode(',', $proxySetting),
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->append(\App\Http\Middleware\InjectDatabaseContext::class);

        $middleware->alias([
            'guest'        => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'auth.session' => \App\Http\Middleware\RequireSessionAuth::class,
            'feature'      => \App\Http\Middleware\FeatureFlagCheck::class,
            'entitlement'  => \App\Http\Middleware\EnforceEntitlements::class,
            // Sanctum ability gates
            'abilities'    => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability'      => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
