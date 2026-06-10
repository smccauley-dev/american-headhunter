# Laravel Models — Conventions & Base Classes

This document defines the base model hierarchy, cross-database relationship patterns, model traits, and the complete model namespace map. Read this before writing any Eloquent model.

---

## Base Model Hierarchy

All models extend one of three abstract base classes. Never extend `Illuminate\Database\Eloquent\Model` directly.

```
App\Models\BaseModel                  (all standard tables)
├── App\Models\BaseModelWithSoftDeletes  (tables with deleted_at)
├── App\Models\ImmutableModel         (DB 9 audit — INSERT only)
└── App\Models\ReadOnlyModel          (DB 8 analytics — no app writes)
```

---

## BaseModel

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    // UUIDs — never auto-increment
    public $incrementing = false;
    protected $keyType   = 'string';

    // Timestamps are managed by PostgreSQL triggers — not Laravel
    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate UUID on create if not provided
        static::creating(function (Model $model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    // Cast created_at and updated_at as Carbon instances.
    // The columns exist on all tables via PostgreSQL trigger.
    // Subclasses call array_merge(parent::casts(), [...]) to extend.
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
```

---

## BaseModelWithSoftDeletes

Used by all user-facing tables that have a `deleted_at` column (the majority of tables):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseModelWithSoftDeletes extends BaseModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'deleted_at' => 'datetime',
        ]);
    }
}
```

The `SoftDeletes` trait adds a global scope that filters `WHERE deleted_at IS NULL` on all queries automatically.

---

## ImmutableModel

Used by all models in `App\Models\Audit\` (DB 9). Overrides all write methods to throw `LogicException`. PostgreSQL RULEs on the database also block UPDATE and DELETE — this is defense in depth.

```php
<?php

namespace App\Models;

abstract class ImmutableModel extends BaseModel
{
    protected $connection = 'audit';

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);   // Allow INSERT (create)
        }
        throw new \LogicException(static::class . ' is immutable — records cannot be updated.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be updated.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be deleted.');
    }
}
```

Audit models do not have `updated_at` or `deleted_at` columns (they use `occurred_at` instead). The `casts()` from BaseModel is overridden:

```php
// In App\Models\Audit\AuditLog:
protected function casts(): array
{
    return [
        'occurred_at'    => 'datetime',
        'changed_fields' => 'array',
        'old_values'     => 'array',
        'new_values'     => 'array',
    ];
}
```

---

## ReadOnlyModel

Used by all models in `App\Models\Analytics\` (DB 8). The `analytics` connection uses `ah_readonly`, which has no INSERT/UPDATE/DELETE permission at the database level. This class also throws at the PHP level for clarity:

```php
<?php

namespace App\Models;

abstract class ReadOnlyModel extends BaseModel
{
    protected $connection = 'analytics';  // ah_readonly user — SELECT only

    public function save(array $options = []): bool
    {
        throw new \LogicException(
            static::class . ' is read-only from the application layer. Write via ETL jobs only.'
        );
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(
            static::class . ' is read-only from the application layer.'
        );
    }

    public function delete(): bool|null
    {
        throw new \LogicException(
            static::class . ' is read-only from the application layer.'
        );
    }
}
```

---

## Mandatory Model Properties

Every concrete model must declare:

```php
protected $connection = 'lease';   // MANDATORY — never rely on default
protected $table      = 'leases';  // MANDATORY — always be explicit

// Inherited from BaseModel — do not redeclare unless you need to change them:
public $incrementing = false;      // UUIDs
protected $keyType   = 'string';   // UUIDs
public $timestamps   = false;      // PostgreSQL triggers manage this
```

---

## Cross-Database Relationships — The Correct Pattern

Eloquent `belongsTo` / `hasMany` / `hasOne` relationships only work when both models share the same database connection. Cross-database references are resolved by service methods, not ORM relationships.

```php
// WRONG — do not do this. The lease connection cannot join to the property connection.
class Lease extends BaseModelWithSoftDeletes
{
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
        // This will fail silently or query the wrong database.
    }
}

// CORRECT — use a getter that delegates to the service layer.
class Lease extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';

    // Cross-DB: property lives in DB 2, lease lives in DB 3
    public function getProperty(): ?\App\Models\Property\Property
    {
        return app(\App\Services\Property\PropertyService::class)
            ->findById($this->property_id);
    }

    // Cross-DB: user lives in DB 1, lease lives in DB 3
    public function getLessee(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)
            ->findById($this->primary_lessee_id);
    }
}
```

**Rule of thumb:** If both the parent and child model have the same `$connection`, a standard Eloquent relationship is fine. If they differ, use a `get*()` service-delegating method.

---

## Example: Complete Model

```php
<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use App\Services\Property\PropertyService;
use App\Services\Identity\UserService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaseApplication extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'lease_applications';

    protected $fillable = [
        'property_id',
        'applicant_user_id',
        'application_type',
        'status',
        'requested_start',
        'requested_end',
        'hunter_count',
        'message',
        'reviewed_by',
        'reviewed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'requested_start' => 'date',
            'requested_end'   => 'date',
            'reviewed_at'     => 'datetime',
            'expires_at'      => 'datetime',
            'hunter_count'    => 'integer',
        ]);
    }

    // ── Relationships within DB 3 (same connection — standard Eloquent) ─────

    public function negotiations(): HasMany
    {
        return $this->hasMany(LeaseNegotiation::class, 'application_id');
    }

    public function lease(): HasOne
    {
        return $this->hasOne(Lease::class, 'application_id');
    }

    // ── Cross-DB getters (service-delegated) ─────────────────────────────────

    // property_id references DB 2 (Property) properties.id
    public function getProperty(): ?\App\Models\Property\Property
    {
        return app(PropertyService::class)->findById($this->property_id);
    }

    // applicant_user_id references DB 1 (Identity) users.id
    public function getApplicant(): ?\App\Models\Identity\User
    {
        return app(UserService::class)->findById($this->applicant_user_id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->whereIn('status', ['submitted', 'under_review', 'info_requested']);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForProperty($query, string $propertyId)
    {
        return $query->where('property_id', $propertyId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return in_array($this->status, ['submitted', 'under_review', 'info_requested']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
```

---

## Model Traits

### HasEncryptedFields

Applied to models with columns marked `-- encrypted` in the schema docs. The trait transparently encrypts on write and decrypts on read using `pgp_sym_encrypt` / `pgp_sym_decrypt` with the per-database key from `config('encryption_keys.<connection>')`.

```php
<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\DB;

trait HasEncryptedFields
{
    // Declare in the model: protected array $encryptedFields = ['gate_code', 'wifi_password'];

    public function setAttribute($key, $value): mixed
    {
        if (in_array($key, $this->encryptedFields ?? [], true) && $value !== null) {
            $encKey = config("encryption_keys.{$this->getConnectionName()}");
            $row    = DB::connection($this->getConnectionName())
                        ->selectOne("SELECT pgp_sym_encrypt(?, ?) AS enc", [$value, $encKey]);
            return parent::setAttribute($key, $row->enc);
        }
        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key): mixed
    {
        $value = parent::getAttribute($key);
        if (in_array($key, $this->encryptedFields ?? [], true) && $value !== null) {
            $encKey = config("encryption_keys.{$this->getConnectionName()}");
            $row    = DB::connection($this->getConnectionName())
                        ->selectOne("SELECT pgp_sym_decrypt(?, ?) AS dec", [$value, $encKey]);
            return $row?->dec;
        }
        return $value;
    }
}
```

Never log the decrypted value of an encrypted field. Never log the encryption key itself.

### BroadcastsAuditEvents

Applied to models whose mutations should be recorded in DB 9. The trait hooks into Eloquent model events and calls `AuditService::log()` after each create, update, or delete:

```php
<?php

namespace App\Models\Traits;

use App\Services\Audit\AuditService;

trait BroadcastsAuditEvents
{
    protected static function bootBroadcastsAuditEvents(): void
    {
        static::created(fn($m)  => app(AuditService::class)->log('create', $m));
        static::updated(fn($m)  => app(AuditService::class)->log('update', $m, $m->getDirty()));
        static::deleted(fn($m)  => app(AuditService::class)->log('delete', $m));
    }
}
```

`AuditService` catches its own exceptions — audit failures never bubble up and break the main operation. See `laravel_services.md` for details.

---

## Model Namespace Map

| Namespace | DB | Connection |
|---|---|---|
| `App\Models\Identity\` | 1 | `identity` |
| `App\Models\Property\` | 2 | `property` (writes) / `property_read` (reads via service) |
| `App\Models\Lease\` | 3 | `lease` |
| `App\Models\Billing\` | 4 | `billing` |
| `App\Models\Wildlife\` | 5 | `wildlife` (writes) / `wildlife_read` (reads via service) |
| `App\Models\Commerce\` | 6 | `commerce` |
| `App\Models\Communications\` | 7 | `communications` |
| `App\Models\Analytics\` | 8 | `analytics` (ReadOnlyModel) |
| `App\Models\Audit\` | 9 | `audit` (ImmutableModel) |
| `App\Models\Incidents\` | 10 | `incidents` |
| `App\Models\Documents\` | 11 | `documents` |
| `App\Models\Platform\` | 12 | `platform` |
| `App\Models\Geospatial\` | 13 | `geospatial` (writes) / `geospatial_read` (reads via service) |

DB 14 (`research`) has no application models. ETL jobs access it via raw queries on the `research` connection.

---

## Model File Map

```
app/Models/
├── BaseModel.php
├── BaseModelWithSoftDeletes.php
├── ImmutableModel.php
├── ReadOnlyModel.php
├── Traits/
│   ├── HasEncryptedFields.php
│   └── BroadcastsAuditEvents.php
│
├── Identity/
│   ├── User.php
│   ├── UserProfile.php
│   ├── GuardianRelationship.php
│   ├── Role.php
│   ├── Permission.php
│   ├── MfaConfiguration.php           -- hidden: secret_encrypted
│   ├── MfaChallenge.php               -- hidden: code_hash
│   ├── OauthConnection.php            -- hidden: tokens
│   ├── ApiKey.php                     -- hidden: key_hash
│   ├── EmailVerificationToken.php
│   ├── PasswordResetToken.php
│   ├── BackgroundCheckResult.php      -- hidden: raw_result_encrypted
│   ├── OfacScreeningResult.php        -- hidden: match_details_encrypted
│   ├── IdentityVerification.php
│   ├── VeteranVerification.php
│   ├── TrustScoreEvent.php            -- append-only (no soft delete)
│   ├── LoginHistory.php               -- append-only (no soft delete)
│   └── ConsentLog.php                 -- append-only (no soft delete)
│
├── Property/
│   ├── Property.php
│   ├── PropertySpecies.php
│   ├── PropertyAmenity.php
│   ├── PropertyPhoto.php
│   ├── PropertyVideo.php
│   ├── PropertyInfrastructure.php
│   ├── WaterBody.php
│   ├── StockingRecord.php
│   ├── CampRegistration.php
│   ├── PropertyAvailability.php
│   ├── PropertyPricing.php
│   ├── CarbonCreditData.php
│   ├── PropertyE911Info.php
│   └── PropertyAccessInfo.php      -- HasEncryptedFields (gate_code, wifi_password)
│
├── Lease/
│   ├── LeaseApplication.php
│   ├── LeaseNegotiation.php
│   ├── Lease.php
│   ├── LeaseTemplate.php
│   ├── LeaseAddendum.php
│   ├── LeaseSignatory.php
│   ├── SignatureEvent.php           -- never soft-deleted
│   ├── LeaseRenewal.php
│   ├── LeasePause.php
│   ├── EarlyTermination.php
│   ├── LeaseAssignment.php
│   ├── Club.php
│   ├── ClubMember.php
│   ├── ClubGovernance.php
│   ├── GuestPass.php
│   ├── HuntSchedule.php
│   ├── CheckInLog.php
│   ├── SecurityDepositRecord.php
│   └── WaitlistEntry.php
│
├── Billing/
│   ├── PaymentMethod.php
│   ├── Invoice.php
│   ├── InvoiceLineItem.php
│   ├── Payment.php
│   ├── Subscription.php
│   ├── PaymentPlan.php
│   ├── StripeConnectAccount.php
│   ├── Payout.php
│   ├── EscrowHold.php
│   ├── Refund.php
│   ├── ChargebackDispute.php
│   ├── PromoCode.php
│   ├── GiftCardBalance.php
│   ├── W9Record.php                -- HasEncryptedFields
│   ├── Tax1099Record.php
│   └── LandownerExpense.php
│
├── Wildlife/
│   ├── HarvestLog.php
│   ├── FishingHarvestLog.php
│   ├── GameSighting.php
│   ├── TrailCamera.php
│   ├── TrailCameraImage.php
│   ├── SpeciesQuota.php
│   ├── SeasonCalendar.php
│   ├── CwdZoneDefinition.php
│   ├── CwdAcknowledgment.php
│   ├── FoodPlotRecord.php
│   └── HuntStory.php
│
├── Commerce/
│   ├── AuctionListing.php
│   ├── AuctionBid.php
│   ├── OutfitterProfile.php
│   ├── HuntPackage.php
│   ├── Booking.php
│   ├── ConsultingBooking.php
│   ├── MarketplaceListing.php
│   ├── Order.php
│   ├── SavedSearch.php
│   ├── LeaseWantedPost.php
│   └── OpenHouseEvent.php
│
├── Communications/
│   ├── MessageThread.php
│   ├── Message.php
│   ├── BroadcastMessage.php
│   ├── NotificationLog.php
│   ├── SupportTicket.php
│   ├── TicketReply.php
│   ├── SosEventLog.php             -- never soft-deleted, never deleted at all
│   └── WeatherAlertLog.php
│
├── Analytics/                      -- all extend ReadOnlyModel
│   ├── DailyPlatformMetrics.php
│   ├── PropertyPerformance.php
│   ├── RevenueSnapshot.php
│   └── HarvestAggregate.php
│
├── Audit/                          -- all extend ImmutableModel
│   ├── AuditLog.php
│   ├── AccessLog.php
│   ├── AdminImpersonationLog.php
│   └── LegalHoldFlag.php
│
├── Incidents/
│   ├── IncidentReport.php
│   ├── PropertyDamageClaim.php
│   ├── TrespassReport.php
│   ├── LeaseDispute.php
│   └── ContentModerationQueue.php
│
├── Documents/
│   ├── Document.php
│   ├── EsignatureRequest.php
│   ├── VideoProcessingJob.php
│   ├── QrCodeRegistry.php
│   └── DigitalIdCard.php
│
├── Platform/
│   ├── FeatureFlag.php
│   ├── MembershipPlan.php
│   ├── PlanVersion.php                    -- logically immutable (PostgreSQL RULE blocks UPDATE)
│   ├── FeatureEntitlement.php
│   ├── PromotionalPeriod.php
│   ├── TenantSettings.php
│   ├── NotificationTemplate.php
│   ├── NotificationTemplateVersion.php    -- status: draft → review → production → archived
│   ├── IotDevice.php                      -- SoftDeletes; config JSONB may contain credentials
│   └── AdCampaign.php                     -- SoftDeletes
│
└── Geospatial/
    ├── PropertyBoundary.php
    ├── PropertyCentroid.php
    ├── PropertyZone.php
    ├── StandLocation.php
    ├── WaterBodyGeometry.php
    ├── CampLocation.php
    ├── HarvestLocation.php
    ├── CwdZonePolygon.php
    └── CountyBoundary.php
```

---

## Records That Are Never Deleted

These records must never be soft-deleted or hard-deleted. Do not expose delete actions in admin UI, do not add soft-delete scopes:

- `App\Models\Audit\*` — entire DB 9 is immutable
- `App\Models\Communications\SosEventLog` — life-safety records
- `App\Models\Incidents\` SOS-related records
- `App\Models\Lease\SignatureEvent` — legal record of e-signature events

---

## Pricing / Entitlement Models

`App\Models\Platform\PlanVersion` is logically immutable after creation — changing a plan's pricing or entitlements creates a new `PlanVersion` row. Subscribers keep their grandfathered version until explicitly migrated. The model does not extend `ImmutableModel` (it needs soft-delete for cleanup), but the application must never update a `PlanVersion` that has active subscribers. `EntitlementService` handles this check.
