<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ServiceLayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Audit — singleton: one instance per request, shared across all callers
        $this->app->singleton(
            \App\Services\Audit\AuditService::class
        );

        // Auth services
        $this->app->singleton(\App\Services\Auth\MfaService::class);
        $this->app->singleton(\App\Services\Auth\AuthService::class);
        $this->app->singleton(\App\Services\Auth\SessionService::class);

        // Identity services
        $this->app->singleton(\App\Services\Identity\UserService::class);
        $this->app->singleton(\App\Services\Identity\VerificationService::class);
        $this->app->singleton(\App\Services\Identity\OfacService::class);
        $this->app->singleton(\App\Services\Identity\TrustScoreService::class);
        $this->app->singleton(\App\Services\Identity\GuestHunterService::class);

        // Platform services
        $this->app->singleton(\App\Services\Platform\FeatureFlagService::class);
        $this->app->singleton(\App\Services\Platform\EntitlementService::class);
        $this->app->singleton(\App\Services\Platform\TenantService::class);

        // Property services
        $this->app->singleton(\App\Services\Property\GeospatialService::class);
        $this->app->singleton(\App\Services\Property\PropertyService::class);

        // Lease services
        $this->app->singleton(\App\Services\Lease\ApplicationService::class);
        $this->app->singleton(\App\Services\Lease\ApplicationMessageService::class);
        $this->app->singleton(\App\Services\Lease\LeaseService::class);
        $this->app->singleton(\App\Services\Lease\EsignatureService::class);

        // Document services
        $this->app->singleton(\App\Services\Documents\DocumentService::class);
    }
}
