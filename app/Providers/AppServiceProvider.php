<?php

namespace App\Providers;

use App\Contracts\Sms\SmsDriver;
use App\Models\Identity\PersonalAccessToken;
use App\Services\Mfa\EmailMfaMethod;
use App\Services\Mfa\MfaMethodRegistry;
use App\Services\Mfa\SmsMfaMethod;
use App\Services\Mfa\TotpMfaMethod;
use App\Services\Sms\StubSmsDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Google2FA::class);

        $this->app->bind(SmsDriver::class, StubSmsDriver::class);

        $this->app->singleton(MfaMethodRegistry::class, function ($app) {
            $registry = new MfaMethodRegistry();
            $registry->register($app->make(TotpMfaMethod::class));
            $registry->register($app->make(SmsMfaMethod::class));
            $registry->register($app->make(EmailMfaMethod::class));
            return $registry;
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // DB-managed mail settings override .env when enabled in the admin panel.
        // Failure (e.g. platform DB unavailable during migrations) keeps .env config.
        try {
            $this->app->make(\App\Services\Communications\MailSettingsService::class)->apply();
        } catch (\Throwable) {
            // .env mail config stays in effect
        }

        RateLimiter::for('mfa-verify', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('challenge_token', $request->ip()));
        });

        RateLimiter::for('mfa-send', function (Request $request) {
            return Limit::perMinute(1)->by($request->input('challenge_token', $request->ip()));
        });

        // Stricter than mfa-verify — a wrong recovery code is a stronger attack signal.
        RateLimiter::for('mfa-recover', function (Request $request) {
            return Limit::perMinute(3)->by($request->input('challenge_token', $request->ip()));
        });

        // SEC-008: read-API throttles. Unauthenticated (public/legacy) traffic is
        // capped per-IP; authenticated traffic is capped per token/user (falling
        // back to IP) at a higher ceiling. Counters live in the ratelimit Valkey
        // cluster via the framework's cache-backed limiter.
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // SEC-043: Livewire registers its component-update endpoint globally in the
        // bare `web` group, NOT through the Filament admin panel's middleware. Yet
        // every admin interaction (form saves, table actions) is a Livewire update,
        // so without intervention they run as the non-owner `ah_runtime` role. The
        // admin panel authenticates with Laravel's `web` guard, whose user is
        // resolved by an RLS-protected SELECT on identity.users that happens before
        // any per-user RLS context is set — under ah_runtime that returns zero rows,
        // the guard sees no user, and Filament bounces the request to /admin/login
        // (which then 500s mid-render). Panel routes already fix this with
        // UseSystemDatabaseRole; the Livewire update route must match. Livewire is
        // admin-only here (public/member portals are Inertia/React), so applying it
        // to the single update route is safe.
        $this->app->booted(function () {
            $updateRoute = collect($this->app['router']->getRoutes()->getRoutes())
                ->first(fn ($route) => str((string) $route->getName())->endsWith('livewire.update'));

            $updateRoute?->middleware(\App\Http\Middleware\UseSystemDatabaseRole::class);
        });
    }
}
