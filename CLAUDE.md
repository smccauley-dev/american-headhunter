# CLAUDE.md — American Headhunter

This file is read by Claude Code at the start of every session. It contains everything needed to orient to this codebase without re-explaining architecture each time.

---

## Working Principles

### 1. Think Before Coding
Don't assume. Don't hide confusion. Surface tradeoffs.

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First
Minimum code that solves the problem. Nothing speculative.

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.
- Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes
Touch only what you must. Clean up only your own mess.

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.
- Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution
Define success criteria. Loop until verified.

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

These guidelines are working if: fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

---

## What This Project Is

A full-stack SaaS platform for hunting lease and land management. Think Airbnb + DocuSign + HuntStand + Zillow, purpose-built for the hunting industry. It connects landowners (who lease their property for hunting) with hunters (who pay for access), and wraps every part of that relationship — discovery, bidding, contracts, e-signatures, payments, field operations, safety, and compliance — in one vertical platform.

**Stack:** Laravel 11 · Filament 3 · Inertia.js + React · PostgreSQL 16 + PostGIS · Valkey · Mapbox GL JS · Stripe + Cashier + Connect · Dropbox Sign · Checkr · TaxJar · Tax1099 · Azure Blob · Cloudflare · GitHub Actions · Azure App Service

---

## Architecture in One Paragraph

The platform runs **14 purpose-built PostgreSQL databases** — each isolated by security domain, compliance boundary, and access pattern. No cross-database SQL foreign keys exist anywhere. All multi-database assembly happens at the Laravel service layer. **5 Valkey clusters** handle sessions, app cache, job queue, real-time auction state, and rate limiting separately so a failure in one domain cannot cascade into another. All geometry lives in PostGIS (DB 13) and is never duplicated into other databases.

---

## Documentation Files — Load These for Context

All documentation lives in `docs/`. Always load the relevant files before writing code for a domain. Do not rely on memory — load the file.

### Database Schemas

| File | When to load |
|---|---|
| `docs/data_model/README.md` | Always — conventions, naming rules, cross-DB pattern |
| `docs/data_model/db01_identity.md` | Auth, users, roles, permissions, MFA, trust scores |
| `docs/data_model/db02_property.md` | Properties, listings, photos, species, amenities, pricing |
| `docs/data_model/db03_lease.md` | Leases, applications, clubs, check-in, e-signatures |
| `docs/data_model/db04_billing.md` | Payments, invoices, Stripe, payouts, 1099s |
| `docs/data_model/db05_wildlife.md` | Harvest logs, sightings, trail cameras, quotas, seasons |
| `docs/data_model/db06_commerce.md` | Auctions, marketplace, outfitter bookings, consulting |
| `docs/data_model/db07_communications.md` | Messages, notifications, support tickets, SOS events |
| `docs/data_model/db08_analytics.md` | ETL-populated metrics and reporting tables |
| `docs/data_model/db09_audit.md` | Append-only audit log — immutable, 10yr retention |
| `docs/data_model/db10_incidents.md` | Safety events, disputes, damage claims, moderation |
| `docs/data_model/db11_documents.md` | File metadata, e-sign requests, QR codes, print jobs |
| `docs/data_model/db12_platform.md` | Feature flags, tenant config, IoT, ad campaigns |
| `docs/data_model/db13_geospatial.md` | PostGIS: boundaries, zones, harvest locations, CWD |
| `docs/data_model/db14_research.md` | Air-gapped anonymized dataset — ETL only |

### Laravel Documentation

| File | When to load |
|---|---|
| `docs/laravel/laravel_database_config.md` | DB connections, Valkey config, RLS injection, encryption |
| `docs/laravel/laravel_migrations.md` | Migration structure, custom commands, conventions |
| `docs/laravel/laravel_models.md` | Base model classes, traits, cross-DB relationship pattern |
| `docs/laravel/laravel_services.md` | Service layer architecture, Valkey cache key conventions |
| `docs/laravel/laravel_jobs.md` | Queue job classes per domain |
| `docs/laravel/laravel_filament.md` | Filament 3 admin resources and navigation |
| `docs/filament_page_template.md` | Page scaffold traits, action zones, icon map, CSS reference — load for any new Filament page |
| `docs/design_system.md` | Visual identity — typography, color, components, conventions |
| `docs/american_headhunter_website.jsx` | Reference prototype — living implementation of the design system |
| `docs/signup_flows.md` | 6 signup paths (hunter, landowner, club, outfitter, consultant, seller) |
| `docs/membership_tiers.md` | Tier structure, feature entitlements, pricing admin UI, billing mechanics |
| `docs/promotions_strategy.md` | Founding Landowner, Honeymoon, seasonal, referral, veteran, promo codes |
| `docs/pricing_schema_additions.md` | New DB 12 and DB 4 tables for plans, entitlements, promotions |
| `docs/communications_strategy.md` | In-platform messaging (Laravel Reverb) + Discord community integration |
| `docs/storage_strategy.md` | Object storage — Garage on-prem with Azure Blob migration path |
| `docs/laravel/docker_compose.md` | Local dev stack, Makefile commands |
| `docs/laravel/env.example` | All environment variables and their purpose |

### Product Scope

| File | When to load |
|---|---|
| `docs/american_headhunter_scope.md` | Full product scope — 93 modules, 47 phases. Load when building a feature you haven't seen before to understand its requirements |

---

## Task → Files to Load

Use this as a quick lookup before starting any task:

| Task | Load these files |
|---|---|
| Auth / login / MFA / SSO | `db01_identity.md` + `laravel_models.md` |
| Property listings / search / maps | `db02_property.md` + `db13_geospatial.md` + `laravel_services.md` |
| Lease lifecycle / applications / signing | `db03_lease.md` + `db11_documents.md` + `laravel_models.md` |
| Payments / invoices / Stripe / payouts | `db04_billing.md` + `laravel_services.md` |
| Harvest logging / wildlife / trail cameras | `db05_wildlife.md` + `db13_geospatial.md` |
| Auctions / bidding engine | `db06_commerce.md` + `laravel_services.md` |
| Messaging / notifications / SOS | `db07_communications.md` + `laravel_jobs.md` |
| Analytics / reporting / dashboards | `db08_analytics.md` |
| Audit logging | `db09_audit.md` + `laravel_services.md` |
| Safety incidents / disputes | `db10_incidents.md` |
| File uploads / e-signatures / QR codes | `db11_documents.md` |
| Feature flags / tenant config / IoT | `db12_platform.md` + `laravel_services.md` |
| Geospatial queries / boundaries / heat maps | `db13_geospatial.md` |
| Migrations | `laravel_migrations.md` + relevant `db_*.md` |
| New Eloquent model | `laravel_models.md` + relevant `db_*.md` |
| New service class | `laravel_services.md` + relevant `db_*.md` |
| Filament admin resource | `laravel_filament.md` + relevant `db_*.md` |
| New Filament page (any type) | `docs/filament_page_template.md` — load first, use the matching scaffold trait |
| Any public-facing page / component / UI work | `design_system.md` + `american_headhunter_website.jsx` |
| New page (landing, detail, dashboard) | `design_system.md` + `american_headhunter_website.jsx` + relevant `db_*.md` |
| Signup / onboarding flow for any account type | `signup_flows.md` + `db01_identity.md` + account-specific DB |
| Subscription / membership / pricing logic | `membership_tiers.md` + `pricing_schema_additions.md` + `db12_platform.md` + `db04_billing.md` |
| Feature entitlement check (gate a feature) | `membership_tiers.md` + `pricing_schema_additions.md` (look for EntitlementService) |
| Promotion / discount / free period | `promotions_strategy.md` + `pricing_schema_additions.md` |
| Admin backend for pricing / promos | `laravel_filament.md` + `membership_tiers.md` + `promotions_strategy.md` |
| Messaging, chat, DMs, lease/club/hunt rooms | `communications_strategy.md` + `db07_communications.md` |
| Real-time features (WebSocket, Reverb) | `communications_strategy.md` + `docker_compose_prod.md` |
| Discord integration / community bot | `communications_strategy.md` |
| SOS safety integration with chat | `communications_strategy.md` + `db07_communications.md` + `db10_incidents.md` |
| File uploads, blob storage, S3, photos | `storage_strategy.md` + `db11_documents.md` |
| Docker / local dev | `docker_compose.md` + `env.example` |
| Unfamiliar feature/module | `american_headhunter_scope.md` |

---

## Database Connection Map

Every model and migration must declare its connection explicitly. Never rely on the default.

| Connection key | Database | DB # |
|---|---|---|
| `identity` | Identity & Authentication | 1 |
| `property` | Property & Land | 2 |
| `property_read` | Property read replica | 2 |
| `lease` | Lease & Contract | 3 |
| `billing` | Billing & Payments | 4 |
| `wildlife` | Wildlife & Field Operations | 5 |
| `wildlife_read` | Wildlife read replica | 5 |
| `commerce` | Commerce & Marketplace | 6 |
| `communications` | Communications | 7 |
| `analytics` | Analytics (read-only) | 8 |
| `analytics_etl` | Analytics (ETL writer) | 8 |
| `audit` | Audit & Compliance | 9 |
| `incidents` | Incidents & Safety | 10 |
| `documents` | Documents & Media | 11 |
| `platform` | Platform Configuration | 12 |
| `geospatial` | Geospatial (PostGIS) | 13 |
| `geospatial_read` | Geospatial read replica | 13 |
| `research` | Research Dataset | 14 |

## Valkey Connection Map

| Connection key | Cluster | Purpose |
|---|---|---|
| `sessions` | Cluster 1 | User session tokens, MFA state |
| `default` | Cluster 2 | App cache — property listings, config, lease summaries |
| `queue` | Cluster 3 | Job queue |
| `auction` | Cluster 4 | Live bid state, countdowns, bid locks |
| `ratelimit` | Cluster 5 | Per-user API throttle counters |

---

## Non-Negotiable Rules

These rules are architecture-level constraints. Do not work around them.

### Cross-Database

- **No cross-database SQL foreign keys.** Ever. Cross-database references are UUID columns with a comment noting the source database — they are NOT enforced by the database engine.
- **No Eloquent `belongsTo` or `hasMany` across different database connections.** Cross-DB relationships are resolved by service methods, not ORM magic. See `laravel_models.md` for the correct pattern.
- **Never join across connections in raw SQL.** No `DB::connection('identity')` followed by a join to a `DB::connection('lease')` table. Assemble in PHP via service layer.

### Audit Database (DB 9)

- **Never call `update()` or `delete()` on any audit model.** All audit models extend `ImmutableModel` which throws on write/delete. PostgreSQL RULE blocks it at the DB level regardless.
- **Always write audit events through `AuditService`.** Never write to `audit_log` directly from controllers or models.
- **AuditService must never throw.** Wrap all writes in try/catch — audit failures must not bubble up and break user-facing transactions.

### Analytics Database (DB 8)

- **The app never writes to DB 8.** It is ETL-populated only. The `analytics` connection uses `readonly_user`. Use `analytics_etl` only in ETL job classes.

### Research Database (DB 14)

- **No application service ever touches DB 14.** Only ETL job classes connect to `research`. No controller, model, or service in the application tier should reference this connection.

### Encryption

- **Never hardcode encryption keys.** They come from Azure Key Vault in production and from environment variables in development. Use `config('encryption_keys.<connection>')`.
- **Never log encrypted field values** — even decrypted. Fields marked as encrypted in the schema files (`-- encrypted` comment) must never appear in application logs.

### Billing Database (DB 4)

- **Never store raw card numbers, CVVs, or full PANs.** Stripe tokenized IDs only (`stripe_payment_method_id`, `stripe_charge_id`, etc.). The `payment_methods` table stores last four digits and brand only.
- **Never log payment method details** — even Stripe IDs should not appear in general application logs.

### Pricing & Entitlements — Database-Driven Only

- **Never hardcode subscription prices, tier names, or feature limits in application code.** All pricing and tier definitions live in DB 12 `membership_plans`, `plan_versions`, and `feature_entitlements`.
- **Never hardcode promotion rules.** Launch promos, seasonal discounts, honeymoon periods, referral programs, and promo codes all live in DB 12 `promotional_periods`.
- **Always check feature access via `EntitlementService`** — never compare `user.plan_name` or `subscription.tier` in application logic. Use `$entitlements->can($user, 'feature_key')` instead.
- **Plan versions are immutable.** Changing a plan's pricing or entitlements creates a new `plan_version` row. Existing subscribers keep their grandfathered version unless admin explicitly migrates them.
- **Entitlement cache (Valkey Cluster 2) must be invalidated** on: subscription changes, promo claim activation/expiration, plan version updates.

### Gate Codes & Access Info

- **`property_access_info` is encrypted at rest.** Gate codes, wifi passwords, and cabin codes are stored via `pgp_sym_encrypt`. Always decrypt via the service — never read the raw column and display it.
- **Only active lessees for a property may read access info.** RLS policy enforces this at the DB level but the service layer must also enforce it.

### SOS Events

- **SOS log records are permanent.** Never soft-delete or hard-delete from `sos_event_log` in DB 7 or `sos_incident_records` in DB 10. These are life-safety records.

---

## Filament Admin UI Conventions

### Table Toolbar — Create Button Standard
**All create/add actions belong in the table toolbar (`toolbarActions`), NOT in the page header (`getHeaderActions`).**

- Use `CreateAction::make()->label('Add [ModelName]')` inside `->toolbarActions([...])` in the Table class
- Never put `CreateAction` in `getHeaderActions()` on a list page — that puts it next to the page title which is not the standard
- `canCreate()` on the resource gates the button via `AdminAuth::canManageProperties()` (or the appropriate role check)
- The button label format is `Add [Thing]` — e.g. "Add Property", "Add Amenity"

---

## Coding Conventions

### Models

```php
// Every model declares its connection
protected $connection = 'lease';

// UUIDs — never auto-increment
public $incrementing = false;
protected $keyType   = 'string';

// Timestamps are handled by PostgreSQL triggers, not Laravel
public $timestamps = false;

// Cast timestamps manually
protected function casts(): array
{
    return [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
```

### Migrations

```php
// Always declare connection — never rely on default
protected $connection = 'lease';

// Use raw SQL — never use Laravel's Schema builder for complex types
// (enums, PostGIS geometry, RLS policies, immutability rules)
public function up(): void
{
    DB::connection($this->connection)->statement(<<<SQL
        CREATE TABLE ...
    SQL);
}
```

### Services

```php
// Cache cross-DB assembled data in Valkey Cluster 2
return Cache::store('valkey')->remember(
    "lease_detail:{$leaseId}",
    now()->addMinutes(10),
    fn() => $this->buildLeaseDetail($leaseId)
);

// Invalidate on write
Cache::store('valkey')->forget("lease_detail:{$leaseId}");
```

### Naming

- **Tables:** `snake_case`, plural (`lease_applications`, `harvest_logs`)
- **Primary keys:** `id UUID`
- **Cross-DB reference columns:** `<entity>_id UUID` with SQL comment `-- References DB X (Name) table.id`
- **Indexes:** `idx_<table>_<columns>` for standard, `uq_<table>_<columns>` for unique, `idx_<table>_<col>_gin` for GIN, `idx_<table>_<col>_gist` for GiST
- **Models:** `PascalCase` in namespace matching their database (`App\Models\Lease\LeaseApplication`)
- **Services:** `PascalCase` + `Service` suffix (`LeaseService`, `BillingService`)
- **Jobs:** `PascalCase` verb-noun (`SendLeaseRenewalNotification`, `ProcessHarvestPhoto`)
- **Events:** `PascalCase` past-tense noun (`LeaseActivated`, `HarvestLogged`, `SosTriggered`)

### Soft Deletes

All user-facing records use soft deletes. The active record filter is always `WHERE deleted_at IS NULL`. Never hard-delete in application code.

Exceptions — records that are never deleted:
- `sos_event_log` (DB 7)
- `sos_incident_records` (DB 10)
- Everything in DB 9 (audit)
- `signature_events` (DB 3)

---

## Feature Flags

Before building a feature, check if it's behind a feature flag. Use the helper:

```php
// In PHP
if (feature('auction_module')) { ... }

// In Blade
@if(feature('consulting_marketplace'))
```

Flags are defined in `db12_platform.md` under the `feature_flags` seed data section. The full flag list includes: `auction_module`, `consulting_marketplace`, `outfitter_booking`, `equipment_marketplace`, `club_leases`, `carbon_credits`, `smart_lock_iot`, `bundled_insurance`, `ai_trophy_scoring`, `public_api`, `data_monetization`, `digital_id_cards`, `veteran_discounts`, `youth_programs`, `offline_pwa`, `saml_sso`, `two_person_authorization`, `lease_wanted_board`, `population_modeling`, `wildlife_photography_tourism`, `club_expense_sharing`.

---

## The 5 Portals

The platform has 5 distinct front-end portals, each with its own Filament panel or Inertia layout:

| Portal | Route prefix | Primary user | Key features |
|---|---|---|---|
| **Public Frontend** | `/` | Unauthenticated | Property listings, search, auction browser, marketing |
| **Customer Portal** | `/apply` | Prospective lessees | Applications, negotiations, auctions, outfitter bookings |
| **Member Portal** | `/member` | Active lessees | Lease management, check-in, harvest logging, field tools |
| **Admin Backend** | `/admin` | Staff + landowners | Filament — property management, lease oversight, support |
| **Reporting Suite** | `/reports` | Landowners + admins | Analytics, harvest data, financial summaries |

---

## Queue Jobs by Priority

Jobs run on Valkey Cluster 3. The queue has two named queues:

**`priority` queue** (processed first — retry_after 30s):
- SOS alert dispatch
- Stripe webhook processing
- E-signature webhook processing
- Lease activation after all signatures collected
- OFAC screening results

**`default` queue** (standard — retry_after 90s):
- Email notification delivery
- Push notification delivery
- SMS dispatch
- Video transcoding trigger
- Trail camera AI tagging
- Harvest photo virus scan
- ETL triggers
- 1099 generation
- QR code generation
- PDF/print job generation

---

## Artisan Commands Reference

```bash
# Run all 14 database migrations in dependency order
php artisan migrate:all

# Fresh install with seed data
php artisan migrate:all --fresh --seed

# Migrate a single database
php artisan migrate:single identity
php artisan migrate:single geospatial --fresh

# Start all local services (Docker)
make up

# Fresh local install
make fresh

# Flush app cache (not sessions or queue)
make flush-cache

# psql into specific database
make psql-identity
make psql-geospatial

# Valkey CLI
make valkey-cache
make valkey-queue
make valkey-auction
```

---

## Common Pitfalls

**Don't use `Schema::create()` for this project.** The schema files use raw PostgreSQL DDL — enums, `pgcrypto`, PostGIS geometry types, RLS policies, and immutability rules cannot be expressed through Laravel's schema builder. Always use `DB::connection()->statement(<<<SQL ... SQL)` in migrations.

**Don't assume `DB::table()` uses the right connection.** It uses the `default` connection from `database.php` which is set to `identity`. Always specify: `DB::connection('lease')->table('leases')`.

**Don't use Eloquent relationships for cross-DB references.** `$lease->property` will fail silently or query the wrong database. Use `$lease->getProperty()` which delegates to `PropertyService`.

**Don't write to DB 8 or DB 14 from application code.** Both are populated by ETL jobs only. Writing from application code breaks the data separation these databases were specifically designed to enforce.

**Don't catch exceptions from `AuditService` at the call site.** `AuditService` catches its own exceptions internally. Let it fail silently — the audit failure is logged to the application log and the transaction continues.

**The `research` DB has no `app_user` credential.** If you see connection errors for `research`, it's because the application is trying to use it directly. Only ETL job classes connect to this database.

---

## Where to Find Things in the Codebase

```
app/
├── Console/Commands/       -- Custom Artisan commands (MigrateAll, MigrateSingle, etc.)
├── Http/
│   ├── Controllers/        -- Thin controllers — delegate to services
│   ├── Middleware/         -- InjectDatabaseContext (RLS), FeatureFlagCheck, etc.
│   └── Requests/           -- Form request validation
├── Models/
│   ├── Identity/           -- User, UserProfile, Role, etc. (DB 1)
│   ├── Property/           -- Property, PropertyPhoto, etc. (DB 2)
│   ├── Lease/              -- Lease, LeaseApplication, Club, etc. (DB 3)
│   ├── Billing/            -- Payment, Invoice, Payout, etc. (DB 4)
│   ├── Wildlife/           -- HarvestLog, TrailCamera, etc. (DB 5)
│   ├── Commerce/           -- AuctionListing, AuctionBid, etc. (DB 6)
│   ├── Communications/     -- MessageThread, SosEventLog, etc. (DB 7)
│   ├── Analytics/          -- Read-only reporting models (DB 8)
│   ├── Audit/              -- Immutable audit models (DB 9)
│   ├── Incidents/          -- IncidentReport, LeaseDispute, etc. (DB 10)
│   ├── Documents/          -- Document, EsignatureRequest, etc. (DB 11)
│   ├── Platform/           -- FeatureFlag, TenantSettings, etc. (DB 12)
│   └── Geospatial/         -- PropertyBoundary, StandLocation, etc. (DB 13)
├── Services/
│   ├── Auth/               -- AuthService, MfaService, SessionService
│   ├── Identity/           -- UserService, VerificationService, OfacService
│   ├── Property/           -- PropertyService, GeospatialService
│   ├── Lease/              -- LeaseService, ApplicationService, EsignatureService
│   ├── Billing/            -- BillingService, StripeService, PayoutService
│   ├── Wildlife/           -- HarvestService, QuotaService, CwdService
│   ├── Commerce/           -- AuctionService, MarketplaceService
│   ├── Communications/     -- NotificationService, SosService
│   ├── Audit/              -- AuditService (singleton)
│   ├── Documents/          -- DocumentService, VideoService, QrCodeService
│   └── Platform/           -- FeatureFlagService, TenantService
├── Jobs/                   -- Queue jobs organized by domain
├── Events/                 -- Domain events (LeaseActivated, HarvestLogged, etc.)
├── Listeners/              -- Event listeners
├── DTOs/                   -- Data Transfer Objects for cross-DB assemblies
└── Providers/
    ├── AppServiceProvider.php
    ├── DatabaseServiceProvider.php    -- encryption key injection
    └── ServiceLayerServiceProvider.php

database/
├── migrations/
│   ├── identity/           -- DB 1 migrations
│   ├── platform/           -- DB 12 migrations
│   ├── geospatial/         -- DB 13 migrations (PostGIS)
│   ├── property/           -- DB 2 migrations
│   ├── lease/              -- DB 3 migrations
│   ├── billing/            -- DB 4 migrations
│   ├── wildlife/           -- DB 5 migrations
│   ├── commerce/           -- DB 6 migrations
│   ├── communications/     -- DB 7 migrations
│   ├── audit/              -- DB 9 migrations
│   ├── incidents/          -- DB 10 migrations
│   ├── documents/          -- DB 11 migrations
│   ├── analytics/          -- DB 8 migrations
│   └── research/           -- DB 14 migrations
├── seeders/                -- Organized by database
└── factories/              -- Organized by database

docs/
├── american_headhunter_scope.md
├── build_roadmap.md
├── design_system.md
├── american_headhunter_website.jsx
├── signup_flows.md
├── membership_tiers.md
├── promotions_strategy.md
├── pricing_schema_additions.md
├── communications_strategy.md
├── storage_strategy.md
├── deployment_strategy.md
├── dockerfile.md
├── onprem_docker_compose.md
├── docker_compose_prod.md
├── azure_migration.md
├── cicd_and_migration.md
├── cicd_pipeline.md            -- older version, cicd_and_migration.md is canonical
├── data_model/
│   ├── README.md
│   └── db01_identity.md ... db14_research.md
└── laravel/
    ├── laravel_database_config.md
    ├── laravel_migrations.md
    ├── laravel_models.md
    ├── laravel_services.md
    ├── laravel_jobs.md
    ├── laravel_filament.md
    ├── docker_compose.md
    └── env.example
```

---

## Deployment Documentation

| File | When to load |
|---|---|
| `docs/deployment_strategy.md` | Overview of on-prem → Azure migration path |
| `docs/dockerfile.md` | Dockerfile, entrypoint, Nginx, Supervisor config |
| `docs/onprem_docker_compose.md` | Production Docker Compose for on-prem |
| `docs/docker_compose_prod.md` | Production Docker Compose (full stack) |
| `docs/azure_migration.md` | Step-by-step migration from on-prem to Azure |
| `docs/cicd_and_migration.md` | GitHub Actions pipeline + Azure migration steps (canonical) |

### Deployment Model

The app is containerized. One Docker image runs everywhere.
Infrastructure differences are environment variables only — no code changes between on-prem and Azure.

- **On-prem:** `docker-compose.prod.yml` + local PostgreSQL + local Valkey + Garage (S3-compatible storage) + HashiCorp Vault
- **Azure:** Azure Container Apps + Azure PostgreSQL + Azure Cache + Azure Blob + Azure Key Vault
- **Migration:** Swap services one at a time via `.env` changes. No application code changes required.
