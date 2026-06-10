# Data Model — American Headhunter

## Overview

The platform runs **14 purpose-built PostgreSQL 16 databases** plus **5 Valkey clusters**. Each database is isolated by security domain, compliance boundary, and access pattern. No cross-database SQL joins exist anywhere in the system. All multi-database assembly happens at the Laravel service layer, cached in Valkey Cluster 2.

This architecture exists for four concrete reasons:

1. **Security domain isolation** — PCI-scoped billing data (DB 4) never shares an instance with general application data. Identity credentials (DB 1) are on a hardened, separately audited server. A breach of one database does not expose another.
2. **Compliance boundary enforcement** — Audit records (DB 9) are append-only by PostgreSQL RULE, enforced independently of application code. Research data (DB 14) is air-gapped from the application tier entirely.
3. **Access pattern optimization** — Wildlife logs (DB 5) are write-heavy and SSD-optimized. Geospatial queries (DB 13) run PostGIS on memory-optimized hardware. Analytics (DB 8) runs read-only on a reporting replica. Each database can be tuned, scaled, and backed up independently.
4. **Failure isolation** — A slow query in analytics does not degrade lease checkout. A Valkey failure on the auction cluster does not affect session tokens.

---

## The 14 Databases

| # | Connection Key | Database Name | Purpose |
|---|---|---|---|
| 1 | `identity` | `ah_identity` | Auth, users, roles, MFA, trust scores |
| 2 | `property` / `property_read` | `ah_property` | Properties, listings, amenities, photos |
| 3 | `lease` | `ah_lease` | Leases, applications, clubs, e-signatures |
| 4 | `billing` | `ah_billing` | Payments, invoices, Stripe, 1099s |
| 5 | `wildlife` / `wildlife_read` | `ah_wildlife` | Harvest logs, trail cameras, quotas |
| 6 | `commerce` | `ah_commerce` | Auctions, marketplace, outfitter bookings |
| 7 | `communications` | `ah_communications` | Messages, notifications, SOS events |
| 8 | `analytics` / `analytics_etl` | `ah_analytics` | ETL-populated metrics (read-only from app) |
| 9 | `audit` | `ah_audit` | Append-only compliance audit log |
| 10 | `incidents` | `ah_incidents` | Safety events, disputes, damage claims |
| 11 | `documents` | `ah_documents` | File metadata, e-sign requests, QR codes |
| 12 | `platform` | `ah_platform` | Feature flags, tenant config, IoT, plans |
| 13 | `geospatial` / `geospatial_read` | `ah_geospatial` | PostGIS: boundaries, zones, harvest locations |
| 14 | `research` | `ah_research` | Air-gapped anonymized research dataset |

---

## Cross-Database Reference Pattern

### The Rule

**No cross-database SQL foreign keys exist anywhere in this codebase.** The PostgreSQL engine cannot enforce a foreign key constraint across two separate database connections. Any `REFERENCES` clause that crosses a database boundary would be silently ignored or would error — so we never write one.

### How Cross-DB References Work

Cross-database references are plain UUID columns with a SQL comment declaring their logical source:

```sql
-- In ah_lease (DB 3), referencing ah_property (DB 2):
property_id UUID NOT NULL, -- References DB 2 (Property) properties.id
listing_id  UUID NOT NULL, -- References DB 2 (Property) property_listings.id
```

These columns:
- Are typed `UUID` (or `UUID NULL` for optional references)
- Have an index on them for join-free lookups
- Are **not** enforced by the database engine
- Are **documented** with a `-- References DB X (Name) table.id` comment on the column definition

### Assembly at the Service Layer

Cross-database data is assembled in PHP service classes, never in SQL:

```php
// WRONG — never do this
$lease = DB::connection('lease')->table('leases')
    ->join(DB::connection('property')...) // impossible cross-DB join

// CORRECT — service layer assembly
class LeaseService
{
    public function getLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        return Cache::store('valkey')->remember(
            "lease_detail:{$leaseId}",
            now()->addMinutes(10),
            function () use ($leaseId) {
                $lease    = Lease::find($leaseId);
                $property = $this->propertyService->find($lease->property_id);
                $lessee   = $this->userService->find($lease->lessee_user_id);
                return new LeaseDetailDTO($lease, $property, $lessee);
            }
        );
    }
}
```

### No Eloquent Relationships Across Connections

Eloquent `belongsTo`, `hasMany`, and `hasManyThrough` silently query the wrong database if the related model uses a different connection. Never define cross-DB relationships on models:

```php
// WRONG — will query the wrong database
public function property(): BelongsTo
{
    return $this->belongsTo(Property::class);
}

// CORRECT — delegate to service
public function getProperty(): ?Property
{
    return app(PropertyService::class)->find($this->property_id);
}
```

---

## Naming Conventions

### Tables

- `snake_case`, always plural
- Examples: `lease_applications`, `harvest_logs`, `trail_camera_photos`, `tax_1099_records`

### Primary Keys

Every table uses a UUID primary key generated by PostgreSQL:

```sql
id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY
```

- Never use `SERIAL` or `BIGSERIAL`
- Never use auto-increment integers
- Laravel models declare `$incrementing = false` and `$keyType = 'string'`

### Cross-DB Reference Columns

```sql
<entity>_id UUID [NOT NULL | NULL]  -- References DB X (Name) table.id
```

- Always a bare UUID column — no `REFERENCES` clause
- Include the DB number, database name, table name, and column in the comment
- Index every cross-DB reference column: `CREATE INDEX idx_<table>_<col> ON <table>(<col>);`

### Indexes

| Pattern | Example | Use |
|---|---|---|
| `idx_<table>_<col>` | `idx_leases_lessee_user_id` | Standard B-tree |
| `uq_<table>_<col>` | `uq_users_email` | Unique constraint index |
| `idx_<table>_<col>_gin` | `idx_notifications_data_gin` | GIN (JSONB, full-text) |
| `idx_<table>_<col>_gist` | `idx_property_boundaries_geom_gist` | GiST (PostGIS geometry) |

### Eloquent Models

Models live in namespaces matching their database:

```
App\Models\Identity\User
App\Models\Identity\UserProfile
App\Models\Property\Property
App\Models\Property\PropertyListing
App\Models\Lease\Lease
App\Models\Lease\LeaseApplication
App\Models\Billing\Invoice
App\Models\Billing\Payment
App\Models\Wildlife\HarvestLog
App\Models\Commerce\AuctionListing
App\Models\Communications\MessageThread
App\Models\Analytics\PropertyMetric        (read-only)
App\Models\Audit\AuditLog                  (ImmutableModel)
App\Models\Incidents\IncidentReport
App\Models\Documents\Document
App\Models\Platform\FeatureFlag
App\Models\Geospatial\PropertyBoundary
```

### Service Classes

`PascalCase` + `Service` suffix, organized under `app/Services/<Domain>/`:

```
App\Services\Auth\AuthService
App\Services\Identity\UserService
App\Services\Property\PropertyService
App\Services\Lease\LeaseService
App\Services\Billing\BillingService
App\Services\Wildlife\HarvestService
App\Services\Commerce\AuctionService
App\Services\Communications\NotificationService
App\Services\Audit\AuditService
App\Services\Documents\DocumentService
App\Services\Platform\FeatureFlagService
```

### Jobs

`PascalCase` verb-noun: `SendLeaseRenewalNotification`, `ProcessHarvestPhoto`, `GenerateTaxForm1099`

### Events

`PascalCase` past-tense noun: `LeaseActivated`, `HarvestLogged`, `SosTriggered`, `BidPlaced`

---

## Timestamp Convention

**Timestamps are managed by PostgreSQL triggers, not Laravel.**

Every table that has timestamps includes these columns:

```sql
created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
```

A trigger fires `BEFORE UPDATE` to set `updated_at = NOW()` automatically. Laravel is not responsible for setting these values.

**All Eloquent models must declare:**

```php
public $timestamps = false;
```

**Timestamps are cast manually in the model's `casts()` method:**

```php
protected function casts(): array
{
    return [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
```

Do not use `HasTimestamps`, `usesTimestamps()`, or any Laravel magic for timestamp management on these models.

---

## Soft Delete Convention

All user-facing records use soft deletes:

```sql
deleted_at TIMESTAMPTZ NULL
```

- **Active record filter:** `WHERE deleted_at IS NULL`
- Application code uses Laravel's `SoftDeletes` trait where `deleted_at` is present
- Hard deletes are prohibited in application code for user-facing data

### Exceptions — Records That Are Never Deleted

The following tables have no `deleted_at` column and must never be deleted (hard or soft):

| Table | Database | Reason |
|---|---|---|
| Everything in DB 9 | `audit` | Compliance — append-only by design and PostgreSQL RULE |
| `sos_event_log` | `communications` (DB 7) | Life-safety record — legally permanent |
| `sos_incident_records` | `incidents` (DB 10) | Life-safety record — legally permanent |
| `signature_events` | `lease` (DB 3) | E-signature audit trail — legally permanent |
| `audit_bids` | `commerce` (DB 6) | Immutable bid record — auction integrity |
| `login_history` | `identity` (DB 1) | Security audit — append-only |
| `trust_score_events` | `identity` (DB 1) | Score audit trail — append-only |
| `ofac_screening_results` | `identity` (DB 1) | Compliance — append-only |

---

## Encryption Conventions

### Method

All sensitive fields are encrypted using PostgreSQL's `pgp_sym_encrypt` function from the `pgcrypto` extension. Encryption keys are stored in Azure Key Vault (production) or environment variables (development) and are never hardcoded.

```sql
-- Write
UPDATE property_access_info
SET access_info_encrypted = pgp_sym_encrypt($1, $2)
WHERE id = $3;

-- Read
SELECT pgp_sym_decrypt(access_info_encrypted::bytea, $1) AS access_info
FROM property_access_info
WHERE property_id = $2;
```

Keys are accessed via `config('encryption_keys.<connection>')` — never via hardcoded strings.

### Encrypted Columns Are Marked in Schema Files

Every column that is encrypted at rest is annotated:

```sql
secret_encrypted TEXT  -- encrypted (pgp_sym_encrypt)
```

**Never log encrypted field values, even after decryption.** Fields marked as encrypted must never appear in application logs, exception messages, or stack traces.

### Databases Using pgcrypto

| Database | Why |
|---|---|
| DB 1 (Identity) | MFA secrets, OAuth tokens, background check results, OFAC match details |
| DB 2 (Property) | Property access info (gate codes, wifi passwords, cabin codes) |
| DB 4 (Billing) | No raw card data stored — Stripe tokens only. Encryption used for tax ID fields |
| DB 7 (Communications) | Push subscription auth keys |
| DB 10 (Incidents) | Dispute evidence, PII in incident records |
| DB 11 (Documents) | Document signing secrets |

---

## Row Level Security (RLS)

### What It Is

PostgreSQL Row Level Security (RLS) restricts which rows a database user can see or modify, enforced at the database engine level — independent of application code.

### How It Works in This Project

The Laravel middleware `InjectDatabaseContext` sets a session-level variable before every request:

```sql
SET app.current_user_id = '<uuid>';
SET app.current_role    = 'hunter';
```

RLS policies on sensitive tables reference these variables:

```sql
CREATE POLICY lessee_can_read_own_leases ON leases
    FOR SELECT TO ah_app
    USING (lessee_user_id = current_setting('app.current_user_id')::UUID
        OR lessor_user_id = current_setting('app.current_user_id')::UUID);
```

### Databases With RLS Policies

| Database | Tables Protected |
|---|---|
| DB 1 (Identity) | `mfa_configurations`, `api_keys`, `background_check_results` |
| DB 2 (Property) | `property_access_info` (lessees only), `property_availability` |
| DB 3 (Lease) | `leases`, `lease_hunters`, `check_ins`, `lease_notes` |
| DB 4 (Billing) | `invoices`, `payments`, `payment_methods`, `payouts` |
| DB 7 (Communications) | `message_threads`, `messages`, `support_tickets` |
| DB 10 (Incidents) | `incident_reports`, `lease_disputes` |

RLS is a defense-in-depth measure. The service layer enforces authorization first; RLS is the database-level backstop.

---

## Read Replica Connections

Three databases have read replicas for high-volume read operations:

| Write Connection | Read Connection | Database |
|---|---|---|
| `property` | `property_read` | DB 2 — Property |
| `wildlife` | `wildlife_read` | DB 5 — Wildlife |
| `geospatial` | `geospatial_read` | DB 13 — Geospatial |

The `property_read` and `wildlife_read` connections use the `ah_readonly` database user (read-only). Always route search, listing browse, and reporting queries to the `_read` connection. Always route writes and lease-critical reads to the primary connection.

```php
// Read — use replica
Property::on('property_read')->where('status', 'active')->paginate(20);

// Write — use primary
$property = new Property();
$property->setConnection('property');
$property->save();
```

---

## ETL-Only Connections

Two connections are restricted to ETL job classes and must never appear in application services, controllers, or models:

### `analytics_etl` (DB 8)

The application never writes to DB 8. The `analytics` connection uses `ah_readonly` — reads only. `analytics_etl` uses `ah_etl_writer` — only ETL job classes in `App\Jobs\Etl\` use this connection.

```php
// WRONG — application code writing to analytics
DB::connection('analytics_etl')->table('property_metrics')->insert(...);

// CORRECT — only in ETL job classes
class AggregatePropertyMetricsJob implements ShouldQueue
{
    protected $connection = 'analytics_etl';
    // ...
}
```

### `research` (DB 14)

DB 14 has no `ah_app` credential. Only ETL jobs connecting as `ah_research_etl` can write to this database. Any connection attempt from application code will fail with an authentication error by design.

---

## The ImmutableModel Pattern (DB 9 — Audit)

All models in `App\Models\Audit\` extend `ImmutableModel`:

```php
abstract class ImmutableModel extends Model
{
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Audit records are immutable.');
    }

    public function delete(): bool
    {
        throw new \LogicException('Audit records cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('Audit records cannot be force deleted.');
    }
}
```

Additionally, the `ah_audit` database uses a PostgreSQL RULE to block `UPDATE` and `DELETE` at the engine level regardless of which database user connects.

**Always write audit events through `AuditService`.** Never write to `audit_log` directly from controllers or models. `AuditService` must never throw — all writes are wrapped in try/catch internally.

---

## Migration File Organization

Migrations are organized by database connection under `database/migrations/`:

```
database/migrations/
├── identity/           -- DB 1 migrations
├── property/           -- DB 2 migrations
├── lease/              -- DB 3 migrations
├── billing/            -- DB 4 migrations
├── wildlife/           -- DB 5 migrations
├── commerce/           -- DB 6 migrations
├── communications/     -- DB 7 migrations
├── analytics/          -- DB 8 migrations
├── audit/              -- DB 9 migrations
├── incidents/          -- DB 10 migrations
├── documents/          -- DB 11 migrations
├── platform/           -- DB 12 migrations
├── geospatial/         -- DB 13 migrations
└── research/           -- DB 14 migrations
```

Every migration class declares its connection explicitly:

```php
class CreateLeasesTable extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            CREATE TABLE leases (
                id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                ...
            );
        SQL);
    }
}
```

**Never use `Schema::create()` or Laravel's schema builder for complex types.** PostgreSQL enums-as-CHECK, `pgcrypto`, PostGIS geometry types, RLS policies, and immutability rules cannot be expressed through Laravel's schema builder. Always use raw SQL via `DB::connection()->statement()`.

Run all migrations in dependency order:

```bash
php artisan migrate:all
php artisan migrate:all --fresh --seed
php artisan migrate:single identity
php artisan migrate:single geospatial --fresh
```

---

## Summary: Rules That Are Never Broken

| Rule | Reason |
|---|---|
| No cross-database SQL foreign keys | PostgreSQL cannot enforce them across connections |
| No Eloquent relationships across connections | Will silently query wrong database |
| No joins across connections in raw SQL | Impossible — different connection objects |
| No application writes to DB 8 or DB 14 | ETL-only databases by architecture |
| No `update()` or `delete()` on audit models | ImmutableModel + PostgreSQL RULE |
| Always write audit events through AuditService | Consistency, never-throw contract |
| Never hardcode encryption keys | Azure Key Vault in prod, env vars in dev |
| Never log encrypted field values | PII/security compliance |
| Never store raw card numbers | PCI compliance — Stripe tokens only |
| Never delete `sos_event_log` records | Life-safety legal requirement |
| Never delete `signature_events` records | E-signature legal requirement |
| Check features via EntitlementService | Never compare plan names directly |
| Never hardcode prices or tier limits | All pricing is database-driven (DB 12) |
