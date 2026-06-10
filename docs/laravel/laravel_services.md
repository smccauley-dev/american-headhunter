# Laravel Service Layer — Architecture & Conventions

The service layer is the **only place cross-database assembly happens**. No controller, model, Filament resource, or job class should assemble data directly from multiple database connections — that work belongs to a service method. This document defines service conventions, cache patterns, the special-case `AuditService`, and the full service map.

---

## Principles

- **Services own business logic.** Controllers are thin — they validate input, call a service, return a response.
- **Models are dumb.** Models hold relationships (within the same DB), scopes, casts, and helpers. They do not fetch data from other databases.
- **Cross-DB assembly happens here and only here.** If you need data from two databases, write a service method that fetches from each and assembles a DTO.
- **Cache at the service layer.** Services cache their assembled results in Valkey Cluster 2 (`cache` connection). They also own cache invalidation.
- **Services are singletons or scoped.** Registered in `ServiceLayerServiceProvider` — see below.

---

## BaseService

All services extend `App\Services\BaseService`, which provides convenience methods for Valkey Cluster 2 caching:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

abstract class BaseService
{
    /**
     * Cache a result in Valkey Cluster 2 (the 'valkey' store).
     * Key format: <domain>:<entity>:<id>  or  <domain>:<entity>:<hash>
     */
    protected function cache(string $key, \Closure $callback, int $ttlMinutes = 10): mixed
    {
        return Cache::store('valkey')->remember($key, now()->addMinutes($ttlMinutes), $callback);
    }

    protected function cacheForever(string $key, \Closure $callback): mixed
    {
        return Cache::store('valkey')->rememberForever($key, $callback);
    }

    protected function invalidate(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::store('valkey')->forget($key);
        }
    }

    /**
     * Invalidate all keys matching a pattern (uses Valkey SCAN).
     * Use sparingly — SCAN is O(N) on the keyspace.
     */
    protected function invalidatePattern(string $pattern): void
    {
        $valkey = Cache::store('valkey')->getRedis();
        $cursor = 0;
        do {
            [$cursor, $keys] = $valkey->scan($cursor, ['match' => $pattern, 'count' => 100]);
            if (! empty($keys)) {
                $valkey->del($keys);
            }
        } while ($cursor != 0);
    }
}
```

---

## Service Class Conventions

```php
<?php

namespace App\Services\Lease;

use App\Models\Lease\Lease;
use App\Models\Property\Property;
use App\Models\Identity\User;
use App\Services\BaseService;
use App\Services\Property\PropertyService;
use App\Services\Identity\UserService;
use App\DTOs\LeaseDetailDTO;

class LeaseService extends BaseService
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly UserService     $userService,
    ) {}

    // Public interface only — no other service calls private methods on this class.
}
```

Rules:
- Namespace: `App\Services\<Domain>\<Name>Service`
- Constructor injection for dependencies — never use `app()` in constructor
- Services never call other services' private methods — only public interfaces
- Services registered as singletons or scoped in `ServiceLayerServiceProvider`

---

## Valkey Cache Key Conventions

All cache keys follow consistent patterns so they can be reliably invalidated. The cache prefix from `config/cache.php` is prepended automatically by Laravel.

| Key pattern | Store | TTL | Invalidated on |
|---|---|---|---|
| `property:{id}` | `valkey` | 15 min | Property save |
| `property:detail:{slug}` | `valkey` | 15 min | Property save |
| `property:search:{hash}` | `valkey` | 5 min | Any property save in search scope |
| `property:landowner:{user_id}` | `valkey` | 5 min | Property save |
| `lease_detail:{id}` | `valkey` | 10 min | Lease status change |
| `user:profile:{user_id}` | `valkey` | 10 min | User profile update |
| `user_entitlements:{user_id}` | `valkey` | 5 min | Subscription change, promo activation/expiry, plan version update |
| `feature_flag:{key}:{user_id}` | `valkey` | 5 min | Feature flag update |
| `feature_flags:all` | `valkey` | 5 min | Any flag update |
| `cfg:tenant:{tenant_id}` | `valkey` | 30 min | Tenant settings update |
| `cfg:platform:{key}` | `valkey` | 60 min | Platform settings update |
| `trust_score:{user_id}` | `valkey` | 60 min | Score recalculation |
| `auction:state:{auction_id}` | Valkey Cluster 4 (`auction` connection) | Duration of auction | Every bid placed |
| `rate:{user_id}:{action}` | Valkey Cluster 5 (`ratelimit` connection) | Rolling 1 hr | TTL-based expiry — never manually invalidated |

---

## Cross-DB Assembly Pattern

Services assemble data from multiple databases and cache the result. This is the canonical pattern:

```php
<?php

namespace App\Services\Lease;

use App\Models\Lease\Lease;
use App\Models\Lease\LeaseSignatory;
use App\Models\Property\Property;
use App\Models\Identity\User;
use App\Services\BaseService;
use App\Services\Property\PropertyService;
use App\Services\Identity\UserService;
use App\DTOs\LeaseDetailDTO;

class LeaseService extends BaseService
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly UserService     $userService,
    ) {}

    /**
     * Assemble a full lease detail view from three databases.
     * Cached for 10 minutes; invalidated on any lease status change.
     */
    public function getLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        return $this->cache("lease_detail:{$leaseId}", function () use ($leaseId) {
            return $this->buildLeaseDetail($leaseId);
        }, ttlMinutes: 10);
    }

    private function buildLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        // Step 1: Load the lease from DB 3
        $lease = Lease::on('lease')
            ->with(['signatories', 'addenda', 'guestPasses'])
            ->findOrFail($leaseId);

        // Step 2: Load the property from DB 2 (cross-DB — service call)
        $property = $this->propertyService->findById($lease->property_id);

        // Step 3: Load the primary lessee from DB 1 (cross-DB — service call)
        $lessee = $this->userService->findById($lease->primary_lessee_id);

        // Step 4: Assemble into a DTO
        return new LeaseDetailDTO(
            lease:    $lease,
            property: $property,
            lessee:   $lessee,
        );
    }

    public function activateLease(string $leaseId): void
    {
        $lease = Lease::on('lease')->findOrFail($leaseId);
        $lease->update(['status' => 'active', 'activated_at' => now()]);

        // Invalidate the cached detail
        $this->invalidate("lease_detail:{$leaseId}");
    }

    public function getActiveLeasesForUser(string $userId): \Illuminate\Support\Collection
    {
        return $this->cache("lease:user:{$userId}:active", function () use ($userId) {
            return Lease::on('lease')
                ->where('primary_lessee_id', $userId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->get();
        }, ttlMinutes: 5);
    }
}
```

---

## DTO Pattern

Data Transfer Objects live in `App\DTOs\` and are used for cross-database assembled responses. They are plain PHP classes with constructor promotion — no ORM, no database connections:

```php
<?php

namespace App\DTOs;

use App\Models\Lease\Lease;
use App\Models\Property\Property;
use App\Models\Identity\User;

readonly class LeaseDetailDTO
{
    public function __construct(
        public Lease     $lease,
        public Property  $property,
        public ?User     $lessee,
    ) {}
}
```

DTOs are what controllers return as JSON, what Inertia pages receive as props, and what Filament custom columns display. They are never cached directly as Eloquent model collections — serialize to arrays or typed DTOs before caching.

---

## AuditService — Special Rules

`AuditService` is a singleton that writes to DB 9. It has three non-negotiable rules:

1. **Must never throw.** It catches all exceptions internally and logs them to the application log. Audit failures do not propagate.
2. **Never call inside a database transaction.** The audit write goes to a different database connection and commits regardless of whether the outer transaction rolls back.
3. **Always call after the primary operation succeeds.** Write the audit record after the model is saved, not before.

```php
<?php

namespace App\Services\Audit;

use App\Models\Audit\AuditLog;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditService extends BaseService
{
    /**
     * Record a model mutation in DB 9.
     * Called by BroadcastsAuditEvents trait on every model event.
     * Also called directly by jobs and services for non-model mutations.
     */
    public function log(
        string $eventType,
        Model  $model,
        array  $changedFields = [],
    ): void {
        try {
            AuditLog::on('audit')->create([
                'event_type'      => $eventType,
                'source_database' => $model->getConnectionName(),
                'table_name'      => $model->getTable(),
                'record_id'       => $model->getKey(),
                'user_id'         => auth()->id(),
                'session_id'      => session()->getId(),
                'action_summary'  => "{$eventType} on {$model->getTable()}:{$model->getKey()}",
                'changed_fields'  => $changedFields ?: null,
                'old_values'      => $eventType === 'update' ? $model->getOriginal() : null,
                'new_values'      => in_array($eventType, ['create', 'update'])
                                        ? $model->getAttributes()
                                        : null,
                'ip_address'      => request()->ip(),
                'user_agent'      => request()->userAgent(),
                'occurred_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // Log the failure — never re-throw.
            Log::critical('AuditService write failed', [
                'error'      => $e->getMessage(),
                'event_type' => $eventType,
                'model'      => get_class($model),
                'record_id'  => $model->getKey(),
            ]);
        }
    }
}
```

---

## EntitlementService

Always use `EntitlementService` to gate features. Never inspect `user->plan_name`, `subscription->tier`, or any string comparison on plan names in application code. Plan names can change; the entitlement system is the authoritative source of truth.

```php
<?php

namespace App\Services\Platform;

use App\Models\Identity\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;

class EntitlementService extends BaseService
{
    /**
     * Check if a user can access a named feature.
     *
     * Usage:
     *   app(EntitlementService::class)->can($user, 'feature_key')
     *
     * Feature keys correspond to feature_entitlements.feature_key in DB 12.
     */
    public function can(User $user, string $featureKey): bool
    {
        $entitlements = $this->getUserEntitlements($user);
        return in_array($featureKey, $entitlements, true);
    }

    private function getUserEntitlements(User $user): array
    {
        return $this->cache("user_entitlements:{$user->id}", function () use ($user) {
            // Load the user's active subscription and its plan version
            // Load feature_entitlements for that plan version
            // Layer in any active promotional_period overrides
            // Return list of feature_key strings the user can access
            return $this->buildEntitlementList($user);
        }, ttlMinutes: 5);
    }

    /**
     * Invalidate a user's entitlement cache.
     * Call this whenever:
     * - Subscription changes (upgrade, downgrade, cancellation)
     * - Promotional period claim activates or expires
     * - Plan version changes (new version released)
     */
    public function invalidateForUser(string $userId): void
    {
        $this->invalidate("user_entitlements:{$userId}");
    }

    private function buildEntitlementList(User $user): array
    {
        // Implementation: query platform DB for active subscription → plan version → entitlements
        // Then check promotional_periods for temporary upgrades
        // Return flat array of feature_key strings
        return [];
    }
}
```

In controllers, Filament resources, and Blade views:

```php
// PHP:
$entitlements = app(EntitlementService::class);
if (! $entitlements->can($user, 'auction_module')) {
    abort(403);
}

// Blade:
@if(feature('auction_module'))
    <x-auction-widget />
@endif

// The feature() helper wraps FeatureFlagService — a different but related check.
// FeatureFlagService checks platform-wide feature flag status.
// EntitlementService checks per-user subscription entitlements.
// Both checks may be needed: the feature must be enabled AND the user must be entitled.
```

---

## FeatureFlagService

```php
<?php

namespace App\Services\Platform;

use App\Models\Identity\User;
use App\Models\Platform\FeatureFlag;
use App\Services\BaseService;

class FeatureFlagService extends BaseService
{
    public function isEnabled(string $flagKey, ?User $user = null): bool
    {
        $cacheKey = "feature_flag:{$flagKey}:" . ($user?->id ?? 'guest');

        return $this->cache($cacheKey, function () use ($flagKey, $user) {
            $flag = FeatureFlag::on('platform')->where('flag_key', $flagKey)->first();

            if (! $flag || ! $flag->is_enabled) return false;

            // Per-user override (allow-list)
            if ($user && ! empty($flag->user_ids) && in_array($user->id, $flag->user_ids)) {
                return true;
            }

            // Percentage rollout — deterministic by user ID
            if ($flag->rollout_pct < 100 && $user) {
                $bucket = crc32($user->id . $flagKey) % 100;
                return $bucket < $flag->rollout_pct;
            }

            return $flag->rollout_pct >= 100;
        }, ttlMinutes: 5);
    }
}
```

The `feature()` helper function (in `app/helpers.php`) calls `FeatureFlagService::isEnabled()`. It is available in PHP and Blade:

```php
// PHP:
if (feature('consulting_marketplace')) { ... }

// Blade:
@if(feature('auction_module'))
    ...
@endif
```

---

## Service Provider Registration

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Audit\AuditService;
use App\Services\Platform\FeatureFlagService;
use App\Services\Platform\TenantService;
use App\Services\Platform\EntitlementService;
use App\Services\Property\PropertyService;
use App\Services\Property\GeospatialService;
use App\Services\Identity\UserService;
use App\Services\Lease\LeaseService;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeService;
use App\Services\Documents\EsignatureService;
use App\Services\Communications\SosService;

class ServiceLayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons — stateless, safe to share across the request
        $this->app->singleton(AuditService::class);
        $this->app->singleton(FeatureFlagService::class);
        $this->app->singleton(TenantService::class);
        $this->app->singleton(EntitlementService::class);
        $this->app->singleton(PropertyService::class);
        $this->app->singleton(GeospatialService::class);
        $this->app->singleton(UserService::class);

        // Scoped — new instance per request (may carry per-request state)
        $this->app->scoped(LeaseService::class);
        $this->app->scoped(BillingService::class);
        $this->app->scoped(StripeService::class);
        $this->app->scoped(EsignatureService::class);
        $this->app->scoped(SosService::class);
    }
}
```

---

## Service Map

```
app/Services/
├── Auth/
│   ├── AuthService.php              -- login, logout, registration
│   ├── MfaService.php               -- TOTP, SMS OTP, backup codes
│   ├── SessionService.php           -- Valkey session management
│   └── SamlService.php              -- SAML SSO (enterprise feature flag)
│
├── Identity/
│   ├── UserService.php              -- user CRUD, profile, trust scores
│   ├── VerificationService.php      -- Checkr, ID.me, DD-214 DD-214 verification
│   ├── OfacService.php              -- OFAC/AML screening
│   └── DataPrivacyService.php       -- CCPA deletion and data export
│
├── Property/
│   ├── PropertyService.php          -- listing CRUD, search, filtering
│   ├── GeospatialService.php        -- PostGIS queries, boundary operations
│   ├── PropertyMediaService.php     -- photo/video upload pipeline
│   └── WeatherAlertService.php      -- NOAA polling and alerting
│
├── Lease/
│   ├── LeaseService.php             -- full lease lifecycle management
│   ├── ApplicationService.php       -- applications and negotiations
│   ├── EsignatureService.php        -- Dropbox Sign integration
│   ├── AccessService.php            -- gate codes, smart lock provisioning
│   ├── ClubService.php              -- club and membership management
│   └── CheckInService.php           -- check-in/out, SOS triggers
│
├── Billing/
│   ├── BillingService.php           -- invoice creation and payment flow
│   ├── StripeService.php            -- Stripe API wrapper
│   ├── PayoutService.php            -- Connect payouts to landowners
│   ├── EscrowService.php            -- security deposits and holds
│   ├── TaxService.php               -- TaxJar sales tax
│   └── Tax1099Service.php           -- 1099 generation and filing
│
├── Wildlife/
│   ├── HarvestService.php           -- harvest log submission and validation
│   ├── QuotaService.php             -- quota tracking and enforcement
│   ├── CwdService.php               -- CWD zone checks and reporting flags
│   └── TrailCameraService.php       -- camera management, AI tagging queue
│
├── Commerce/
│   ├── AuctionService.php           -- auction lifecycle, bid engine
│   ├── MarketplaceService.php       -- equipment listing management
│   ├── OutfitterService.php         -- package and booking management
│   └── ConsultingService.php        -- consulting marketplace
│
├── Communications/
│   ├── NotificationService.php      -- multi-channel notification dispatch
│   ├── MessagingService.php         -- in-app message threads (Reverb)
│   ├── SosService.php               -- SOS alert handling and escalation
│   └── SupportService.php           -- support ticket management
│
├── Audit/
│   └── AuditService.php             -- write to DB 9 (singleton, never throws)
│
├── Documents/
│   ├── DocumentService.php          -- upload, virus scan, versioning
│   ├── VideoService.php             -- transcoding pipeline
│   ├── QrCodeService.php            -- QR generation and scan handling
│   └── PrintService.php             -- PDF generation queue
│
└── Platform/
    ├── FeatureFlagService.php        -- platform-wide feature flag evaluation
    ├── TenantService.php             -- multi-tenancy resolution
    ├── EntitlementService.php        -- per-user subscription entitlement checks
    └── AnalyticsService.php          -- ETL reads from DB 8 analytics
```
