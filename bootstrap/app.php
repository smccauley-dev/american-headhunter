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
            // SEC-043: must run AFTER StartSession so it can read the custom
            // `auth.user_id` session key to set the RLS context.
            \App\Http\Middleware\InjectDatabaseContext::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\InjectDatabaseContext::class,
        ]);

        // SEC-043: the db.system role swap (UseSystemDatabaseRole) MUST run before
        // the auth guard resolves a user — the Filament `web` guard looks the staff
        // user up with an RLS-protected SELECT on identity.users, which returns zero
        // rows under ah_runtime. Route middleware order alone does not guarantee this:
        // Authenticate is in the priority list (via the AuthenticatesRequests contract)
        // and gets sorted ahead of any non-prioritized middleware. Registering
        // db.system in the priority list immediately before that contract guarantees
        // it sorts first wherever it is applied (admin document routes, admin panel).
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\UseSystemDatabaseRole::class,
        );

        $middleware->alias([
            'guest'        => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'auth.session' => \App\Http\Middleware\RequireSessionAuth::class,
            'db.system'    => \App\Http\Middleware\UseSystemDatabaseRole::class,
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
