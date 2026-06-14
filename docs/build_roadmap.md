# American Headhunter — Build Roadmap

A sequenced, phase-by-phase plan to take the platform from documentation to production. Work top to bottom — each phase depends on the one before it.

**Legend:** `[ ]` not started · `[~]` in progress · `[x]` complete

---

## PHASE 1 — Project Skeleton & Local Dev Stack ✅ COMPLETE (2026-05-24)

- [x] Laravel 13 scaffolded with Inertia.js + React starter kit
- [x] `Dockerfile.dev` — PHP 8.4-fpm + Nginx + Supervisor (multi-role entrypoint)
- [x] `docker-compose.yml` — local dev stack (app, 14 PostgreSQL databases, 5 Valkey clusters, Garage, Mailpit)
- [x] PostgreSQL 16 + PostGIS + pgcrypto + uuid-ossp on all 14 databases
- [x] All 14 databases created with init scripts on first container boot
- [x] 5 Valkey cluster containers: sessions, cache, queue, auction, ratelimit
- [x] `config/database.php` — 18 connections (14 databases + 4 read replicas)
- [x] `config/cache.php` — 5 Valkey stores mapped to cluster keys
- [x] `config/queue.php` — Valkey Cluster 3 (`queue` connection) with `priority` and `default` queues
- [x] `config/session.php` — Valkey Cluster 1 (`sessions` connection)
- [x] `config/filesystems.php` — Garage S3-compatible disk (`storage` disk)
- [x] Artisan command: `migrate:all` (runs all 14 in dependency order)
- [x] Artisan command: `migrate:single {database}` (targeted single-DB migration)
- [x] Migration directory structure: `database/migrations/{database}/` for all 14 databases
- [x] `Makefile` — `make up`, `make down`, `make fresh`, `make flush-cache`, `make psql-{db}`, `make valkey-{cluster}`, `make logs`
- [x] `.env.example` with all environment variables documented
- [x] Health-check Artisan command — pings all 14 databases and 5 Valkey clusters
- [x] Full stack boots: `docker compose up` — all containers healthy, Laravel loads, DB + Valkey + Garage connected

---

## PHASE 2 — Identity Foundation

The goal: a working auth system backed by DB 1, with all identity models, services, and registration/login flows.

### 2.1 DB 1 Identity Migrations ✅ (2026-05-24)

Build all migrations in `database/migrations/identity/` using raw PostgreSQL DDL:

- [x] `users` table — UUID PK, account_type enum, email, password_hash, MFA flags, trust_score, is_veteran, discord_user_id
- [x] `user_profiles` — personal info, notification_preferences JSONB, hunting_profile JSONB
- [x] `roles` and `permissions` tables — RBAC definitions
- [x] `user_roles` and `role_permissions` pivot tables
- [x] `mfa_configurations` + `mfa_challenges` — TOTP secrets, backup codes, challenge records
- [x] `oauth_connections` — Google, Apple, Facebook, Discord linked accounts
- [x] `guardian_relationships` — minor ↔ guardian links with consent records
- [x] `background_check_results` — Checkr background check records
- [x] `identity_verifications` — ID.me / Stripe Identity / manual verification records
- [x] `veteran_verifications` — ID.me and DD-214 upload records
- [x] `consent_log` — CCPA and ToS consent events (append-only)
- [x] `password_reset_tokens` + `email_verification_tokens` — standard auth flows
- [x] `api_keys` — public API tokens (scoped, hashed, never stored plaintext)
- [x] `ofac_screening_results` — OFAC SDN screening (append-only)
- [x] `trust_score_events` + `login_history` — audit trails (append-only)
- [x] RLS policies on `users`, `user_profiles`, `mfa_configurations`, `api_keys`, `background_check_results`
- [x] `php artisan migrate:single identity --fresh` — 12/12 zero errors

### 2.2 Base Model Architecture ✅ (2026-05-24)

- [x] `App\Models\BaseModel` — UUID keys, no Laravel timestamps, manual datetime casts
- [x] `App\Models\BaseModelWithSoftDeletes` — extends BaseModel with SoftDeletes trait
- [x] `App\Models\ImmutableModel` — extends BaseModel, overrides `save()`/`update()`/`delete()`/`forceDelete()` to throw `LogicException`
- [x] `App\Models\ReadOnlyModel` — extends BaseModel, forces `analytics` connection, throws on any write

### 2.3 Identity Models ✅ (2026-05-24)

- [x] `App\Models\Identity\User`
- [x] `App\Models\Identity\UserProfile`
- [x] `App\Models\Identity\Role`
- [x] `App\Models\Identity\Permission`
- [x] `App\Models\Identity\MfaConfiguration`
- [x] `App\Models\Identity\MfaChallenge`
- [x] `App\Models\Identity\OauthConnection`
- [x] `App\Models\Identity\ApiKey`
- [x] `App\Models\Identity\BackgroundCheckResult`
- [x] `App\Models\Identity\OfacScreeningResult`
- [x] `App\Models\Identity\IdentityVerification`
- [x] `App\Models\Identity\VeteranVerification`
- [x] `App\Models\Identity\TrustScoreEvent`
- [x] `App\Models\Identity\LoginHistory`
- [x] `App\Models\Identity\ConsentLog`
- [x] `App\Models\Identity\GuardianRelationship`

### 2.4 Service Layer — Identity ✅ (2026-05-24)

- [x] `DatabaseServiceProvider` — injects encryption keys from env; registered in `bootstrap/providers.php`
- [x] `ServiceLayerServiceProvider` — registers all services as singletons
- [x] `App\Services\BaseService` — cache/invalidate helpers (Valkey Cluster 2)
- [x] `App\Services\Auth\AuthService` — login, logout, lockout logic
- [x] `App\Services\Auth\MfaService` — challenge creation, TOTP verify, backup code generation/verification
- [x] `App\Services\Auth\SessionService` — MFA-pending/verified state in Valkey Cluster 1
- [x] `App\Services\Identity\UserService` — user creation, profile management, cache by UUID
- [x] `App\Services\Identity\VerificationService` — email token generation/verification, Checkr webhook handler
- [x] `App\Services\Identity\OfacService` — OFAC screening, match suspension, append-only result log
- [x] `App\Services\Identity\TrustScoreService` — atomic score updates with event log
- [x] `App\Services\Audit\AuditService` — singleton, writes to DB 9, never throws, sanitizes sensitive fields

### 2.5 Middleware ✅ (2026-05-24)

- [x] `InjectDatabaseContext` — sets `app.current_user_id` + `app.current_role` on all DB connections for RLS; appended globally
- [x] `FeatureFlagCheck` — `->middleware('feature:flag_key')` — reads DB 12, caches in Valkey, fails open if DB down
- [x] `EnforceEntitlements` — `->middleware('entitlement:feature_key')` — stub ready for Phase 3 EntitlementService wiring

### 2.6 Auth Flows (API endpoints + Inertia pages) ✅ (2026-05-24)

- [x] `GET /get-started` — account type selection screen (Inertia: `Auth/GetStarted`)
- [x] `POST /register` — creates `users` record, dispatches `SendEmailVerificationJob`
- [x] `GET /email/verify/{token}` — sets `email_verified_at` via `VerificationService`
- [x] `POST /email/verify/resend` — resends verification email
- [x] `GET|POST /login` — Inertia login; checks `isActive()` + MFA branch
- [x] `GET|POST /mfa/verify` — validates TOTP / SMS / email challenge then backup code
- [x] `GET|POST /forgot-password` + `GET|POST /reset-password` — full password reset flow
- [x] `POST /logout` — invalidates session
- [x] `guest` middleware alias — redirects authenticated users away from auth pages
- [x] `auth.session` middleware alias — requires `auth.user_id` in session
- [x] `RegisterRequest` + `LoginRequest` form requests
- [x] `EmailVerificationMail` + `PasswordResetMail` mailables + Blade email templates
- [x] `SendEmailVerificationJob` + `SendPasswordResetJob` queued jobs
- [x] `EmailVerificationToken` + `PasswordResetToken` models (were missing, added)
- [x] All 7 Inertia React auth pages (brand-styled, design-system compliant)
- [x] `@/*` path alias wired in `vite.config.js` + `tsconfig.json`
- [x] `npm run build` — 534 modules, zero TypeScript errors

### 2.7 Seeders ✅ (2026-05-24)

- [x] `RoleSeeder` — 8 system roles (hunter, landowner, club_admin, outfitter, consultant, seller, staff, super_admin)
- [x] `PermissionSeeder` — 26 permissions across all major resource categories
- [x] `TestUserSeeder` — 7 test users (one per account type + admin), password: `Password1!local`
- [x] `php artisan migrate:single identity --fresh --seed` — 13/13 migrations + 3 seeders, zero errors

### Phase 2 Bugs Fixed (2026-05-24)

- [x] `email_verified_at` added to `User::$fillable` — was silently blocked from mass assignment
- [x] `GET /email/verify/{token}` moved outside `auth.session` middleware — works from any device/session
- [x] `VerificationService::createEmailToken()` now expires prior tokens before issuing a new one
- [x] Queue worker restart via `php artisan queue:restart` after job code fixes
- [x] `failed_jobs` migration using `TEXT` (not MySQL-only `MEDIUMTEXT`)
- [x] Jobs use `$user->profile?->first_name ?? ''` — `first_name` lives in `user_profiles`, not `users`
- [x] `config/platform.php` — legal URLs + logout redirect URL (env-driven, DB 12 override in Phase 3)
- [x] Consent checkboxes linked to configurable legal URLs; submit disabled until both checked
- [x] Duplicate email shows error banner with "Sign in instead?" link
- [x] Sign-out redirect URL configurable via `LOGOUT_REDIRECT_URL` env var

### Phase 2 Milestone ✅ (2026-05-24) — Smoke-tested end-to-end

- [x] A user can register (all 6 account types selectable at `/get-started`)
- [x] Email verification flow smoke-tested: register → Mailpit email → click link → user `status=active`
- [x] Login with session, MFA branch, and lockout logic
- [x] MFA challenge verification (TOTP, SMS, email, backup codes)
- [x] OFAC screening dispatched at registration
- [x] Password reset smoke-tested: forgot-password → Mailpit email → reset link loads
- [x] All auth events written to DB 9 audit log via `AuditService`
- [x] `InjectDatabaseContext` sets RLS context on all 14 connections
- [x] **MILESTONE: Auth system functional and smoke-tested end-to-end**

---

## PHASE 3 — Platform & Property Foundation

The goal: feature flags, membership tiers, and property listings working — the first publicly visible layer of the platform.

### 3.1 DB 12 Platform Migrations ✅ (2026-05-24)

- [x] `feature_flags` — 21 flags seeded (club_leases, veteran_discounts, youth_programs enabled)
- [x] `membership_plans` — 12 plans: 4 hunter, 3 landowner, 2 club, 1 outfitter, 1 consultant, 1 seller
- [x] `plan_versions` — v1 immutable snapshot auto-seeded per plan; PostgreSQL RULE blocks UPDATE
- [x] `feature_entitlements` — 60 rows seeded across 8 plans; PHP-seeded to avoid UNION type conflicts
- [x] `promotional_periods` — Founding Landowner (500-slot), Honeymoon (auto first-listing), Veteran (permanent)
- [x] `tenant_settings` — 9 platform defaults (legal URLs, support email, SOS phone, etc.)
- [x] `notification_templates` + `notification_template_versions` — 6 template slugs; versioned with draft/review/production/archived workflow
- [x] `iot_devices` — smart locks, cellular trail cameras, weather stations
- [x] `ad_campaigns` — sponsored listing campaigns
- [x] `php artisan migrate:single platform --fresh` — 10/10 zero errors
- [ ] Commit: "DB 12 Platform migrations"

### 3.2 DB 2 Property Migrations ✅ (2026-05-24)

- [x] `properties` — UUID PK, owner cross-DB ref, slug (unique partial index), address_encrypted (pgp_sym_encrypt), state/county, total_acres, huntable_acres, boundary_geospatial_id, primary_photo_document_id, soft deletes
- [x] `property_listings` — listing_type CHECK (annual_lease/seasonal_lease/day_hunt/auction), status, season dates, hunter limits, price_per_hunter/price_total, deposit logic, visibility, soft deletes
- [x] `property_photos` — document_id cross-DB ref, sort_order, is_primary, soft deletes
- [x] `property_amenities` — seeded with 26 amenities across 6 categories (accommodation, access, water, stand, food_plot, other)
- [x] `property_amenity_listings` — pivot table linking amenities to listings
- [x] `property_species` — species_code CHECK (15 species + other), is_primary flag
- [x] `property_rules` — rule_text per property, sort_order
- [x] `property_access_info` — access_info_encrypted (pgp_sym_encrypt), RLS enabled (staff/super_admin at DB level; full lessee auth in PropertyService)
- [x] `property_availability` — date_start/date_end range blocking (booked/blocked/maintenance)
- [x] `property_views` — append-only view tracking for ETL; `saved_properties` hunter wishlist
- [x] `php artisan migrate:single property --fresh` — 10/10 zero errors; RLS verified on property_access_info

### 3.3 DB 13 Geospatial Migrations ✅ (2026-05-24)

- [x] Extensions: postgis, postgis_topology, fuzzystrmatch, postgis_tiger_geocoder, uuid-ossp — all available in postgis/postgis:16-3.4 Docker image
- [x] `property_boundaries` — GEOMETRY(MULTIPOLYGON, 4326), area_acres GENERATED ALWAYS (ST_Area + ST_Transform to SRID 5070), source CHECK, soft deletes, partial GiST index
- [x] `stand_locations` — GEOMETRY(POINT, 4326), stand_type CHECK (6 types), lease_id cross-DB ref, soft deletes, partial GiST index
- [x] `food_plots` — GEOMETRY(POLYGON, 4326), area_acres GENERATED ALWAYS, species_planted JSONB, soft deletes, GiST index
- [x] `harvest_locations` — GEOMETRY(POINT, 4326), harvest_log_id cross-DB ref, immutable (no updated_at, no deleted_at)
- [x] `trail_camera_locations` — GEOMETRY(POINT, 4326), facing_direction CHECK (0–359), camera_id cross-DB ref, history preserved (no deleted_at)
- [x] `cwd_management_zones` — GEOMETRY(MULTIPOLYGON, 4326), zone_type CHECK (positive/surveillance/management), ETL-managed (no deleted_at)
- [x] `sos_locations` — GEOMETRY(POINT, 4326), sos_event_log_id cross-DB ref, permanent life-safety record (no deleted_at)
- [x] `php artisan migrate:single geospatial --fresh` — 8/8 zero errors; geometry_columns shows all 7 tables with correct types and SRID 4326

### 3.4 Platform Services ✅ (2026-05-24)

- [x] Platform models: `FeatureFlag`, `MembershipPlan`, `PlanVersion`, `FeatureEntitlement`, `PromotionalPeriod`, `TenantSettings`, `NotificationTemplate`, `NotificationTemplateVersion`, `IotDevice`, `AdCampaign`
- [x] `App\Services\Platform\FeatureFlagService` — reads DB 12 `feature_flags` with Valkey Cluster 2 caching (5-min TTL); two-tier eval: global is_enabled + per-user rollout bucket + allow-list; `feature()` global helper in `app/helpers.php`
- [x] `App\Services\Platform\EntitlementService` — `can(User, featureKey): bool`, `limit(User, featureKey): int`, `value(User, featureKey): ?string`; Valkey Cluster 2 cache (5-min TTL); Phase 3 resolves from default plan by account_type; Phase 4 will query billing subscriptions
- [x] `App\Services\Platform\TenantService` — `getSetting(key)` / `setSetting(key, value)` with dot-notation keys (e.g. `platform.name`, `billing.currency`); convenience wrappers for platformName, sosPhone, foundingLandownerSlots
- [x] `app/Support/Entitlements.php` — typed constants for all 23 feature keys (hunter, landowner, club categories)
- [x] `app/helpers.php` — `feature(string, ?User): bool` global helper registered via composer.json `autoload.files`
- [x] `ServiceLayerServiceProvider` — FeatureFlagService, EntitlementService, TenantService registered as singletons
- [x] Bug fixed: FeatureFlagService rollout logic — no-user context now returns `is_enabled` directly (rollout_percentage only applies when a user is provided for A/B gating)

### 3.5 Property Services ✅ (2026-05-24)

- [x] Property models (all extend `BaseModel`/`BaseModelWithSoftDeletes`): `Property`, `PropertyListing`, `PropertyPhoto`, `PropertyAmenity`, `PropertySpecies`, `PropertyRule`, `PropertyAccessInfo`, `PropertyAvailability`, `SavedProperty`
- [x] Geospatial models (all extend `BaseModel`/`BaseModelWithSoftDeletes`): `PropertyBoundary`, `StandLocation`, `FoodPlot`, `HarvestLocation`, `TrailCameraLocation`, `CwdManagementZone`, `SosLocation`
- [x] `App\Services\Property\PropertyService` — find/findBySlug/getPropertiesForOwner/searchListings (with state/county/species/price/acreage filters), createProperty/updateProperty/deleteProperty/createListing/publishListing, getAccessInfo (pgp_sym_decrypt), setAccessInfo (pgp_sym_encrypt upsert), recordView (append-only ETL), generateSlug (collision-safe), invalidatePropertyCache; Valkey Cluster 2 caching on all reads
- [x] `App\Services\Property\GeospatialService` — getBoundary/getPropertyBoundaryGeoJson (cached 30 min), storePropertyBoundary (ST_SetSRID/ST_GeomFromGeoJSON raw INSERT), isPointWithinProperty (ST_Within), getStandsNearPoint (ST_DWithin geography), getPropertyStandsGeoJson (FeatureCollection), storeStandLocation, getIntersectingCwdZones (ST_Intersects), getCwdZonesForPoint
- [x] `EnforceEntitlements` middleware wired — `EntitlementService` injected via constructor; returns 401 if unauthenticated, 403 if plan does not include feature
- [x] Smoke-tested: `Property::create()` returns UUID; `storePropertyBoundary()` returns UUID string, verified in DB via raw SELECT
- [x] Bug fixed: all Property and Geospatial models now extend `BaseModel`/`BaseModelWithSoftDeletes` — models extending `Model` directly do not fire `BaseModel::creating` hook, leaving `id` empty after INSERT

### 3.6 Property Management (Admin — Filament) ✅ (2026-05-24)

- [x] Filament 5.6.5 installed (Laravel 13 requires Filament 5; Filament 3 maxes at Laravel 12)
- [x] `AdminPanelProvider` — brand colors (ink/blaze/brass/sage), navigation groups (Marketplace, Users & Access, Pricing & Promotions, Communications, Safety & Compliance, System), discovery paths set to `app/Filament/Admin/{Resources,Pages,Widgets}/`
- [x] `App\Models\Identity\User` — implements `FilamentUser` + `AuthenticatableContract`; `canAccessPanel()` restricts to staff/super_admin; `getAuthPassword()` maps to `password_hash` column
- [x] `config/auth.php` — `providers.users.model` updated to `App\Models\Identity\User`
- [x] `PropertyResource` — List/View/Edit pages; `canCreate()` returns false (properties created via landowner onboarding); navigation group: Marketplace; globally searchable by title/slug/state/county
- [x] `PropertiesTable` — status badge with color coding, state/county/acreage columns, state filter, trash filter, default sort by newest; Add Property button in toolbar
- [x] `PropertyForm` — sectioned (Listing Info / Location / Acreage); address_encrypted excluded (never exposed in UI); boundary/photo UUIDs excluded (managed by services)
- [x] `PropertyInfolist` — full detail view with collapsed internal reference and timestamp sections
- [x] `FeatureFlagResource` — single-page manage modal; `canDelete()` false; `canCreate()` gated to `canManageSystem()` (super_admin only); key is read-only on edit; cache invalidated via `FeatureFlagService::invalidateFlag()` after each save; Add Feature Flag in toolbar; navigation group: System
- [x] Smoke-tested: `/admin/login` returns 200; 7 routes registered; unauthenticated `/admin` → 302 redirect to login
- [ ] Photo upload panel — deferred to Phase 4.2 (requires DB 11 Documents)
- [ ] Mapbox boundary editor — deferred to Phase 3.7 (done on public property detail page)
- [ ] Stand registry / species panels — deferred to Phase 4 (requires active lease context)
- [x] Amenities admin resource — completed in Phase 3.9 below

### 3.7 Property API & Public Frontend ✅ (2026-05-24)

- [x] `GET /api/properties` — paginated, filterable (state, species, acreage, price, lease type) via `PropertyService::searchListings()`
- [x] `GET /api/properties/{id}` — listing detail with relations loaded
- [x] `GET /api/properties/{id}/boundary` — GeoJSON Feature via `GeospatialService::getPropertyBoundaryGeoJson()`
- [x] `routes/api.php` registered in `bootstrap/app.php` via `->withRouting(api: ...)`
- [x] `App\Http\Controllers\Api\PropertyController` — index/show/boundary endpoints
- [x] `GET /` via `HomeController` (invokable) — passes 6 featured listings as Inertia props; graceful fallback on DB unavailable
- [x] `GET /properties/{slug}` via `App\Http\Controllers\Public\PropertyController` — passes property with all relations
- [x] Full marketing landing page (`resources/js/Pages/Home.tsx`) — nav/hero/search/chapter I–III/testimonials/CTA/footer
- [x] Property detail page (`resources/js/Pages/Public/PropertyDetail.tsx`) — header/gallery/map placeholder/description/species/rules/apply card
- [x] Design system applied: all CSS classes from `app.css` used throughout both pages
- [x] `npm run build` — 537 modules, zero TypeScript errors, zero broken imports; 374 kB JS, 82.9 kB CSS
- [ ] Commit: "Property API and public listing frontend"

### 3.8 Seeders ✅ (2026-05-25)

- [x] `FeatureFlagSeeder` — 21 flags seeded (idempotent `updateOrInsert`)
- [x] `PromotionalPeriodSeeder` — Founding Landowner (500-slot), Honeymoon (auto), Veteran (permanent); idempotent
- [x] `PlatformSeeder` — orchestrates FeatureFlagSeeder + PromotionalPeriodSeeder
- [x] `SamplePropertySeeder` — 4 sample properties (TX Whitetail, KS Elk, TX Waterfowl, WV Turkey) with listings and species; idempotent by slug check
- [x] `PropertySeeder` — orchestrates SamplePropertySeeder
- [x] `DatabaseSeeder` — calls Identity seeders → PlatformSeeder → PropertySeeder in dependency order
- [x] `2026_05_24_000011_grant_readonly_permissions.php` — GRANT SELECT + USAGE on `property` DB to `ah_readonly`; `ALTER DEFAULT PRIVILEGES` for future tables
- [x] Bug fixed: Valkey `$this->cache()` wrapper removed from `find()`, `findBySlug()`, `findListing()`, `getPropertiesForOwner()` — Eloquent model serialization across request boundaries causes `__PHP_Incomplete_Class` deserialization errors; paginated and single-model reads now bypass Valkey
- [x] `php artisan db:seed` — all seeders pass, zero errors; home page loads 4 listings, property detail page returns 200

### Phase 3 Milestone ✅ (2026-05-25)

- [x] Feature flags toggle and gates work — `feature('club_leases')` → ON, `feature('auction_module')` → OFF; `feature()` helper live-reads DB 12 with Valkey caching
- [x] `EntitlementService::can()` and `limit()` return correct values from DB 12 with Valkey caching; `TenantService` reads platform settings from DB 12
- [x] A property can be created via Filament admin (`PropertyResource` — List/View/Edit pages); photo upload and boundary editor deferred to Phase 4.2
- [x] Public frontend loads at `/` — 4 sample listings rendered with species pills, acreage, price, and county/state; `GET /` → 200
- [x] Property detail page loads at `/properties/{slug}` — species, rules, listings, and apply card render correctly; `GET /properties/brackettville-whitetail-ranch` → 200
- [x] `GET /api/properties` (paginated JSON) → 200; `GET /api/properties/{id}/boundary` (GeoJSON) → 200
- [ ] All property actions logged to DB 9 via `AuditService` — deferred to Phase 4 (requires lease context for meaningful audit events)
- [x] **MILESTONE: Platform foundation and property discovery working end-to-end**
- [x] Security audit complete — SEC-001 through SEC-009 fixed and verified; `security.md` tracking file created; open items: SEC-003-P4 (Phase 4), SEC-006/007/008 (Phase 3 close-out / Phase 4)
- [x] Homepage CMS — all copy and section visibility flags driven by DB 12 `tenant_settings`; `HomepageSettings` Filament admin page at `/admin/homepage-settings`; 29 settings seeded via `HomepageSettingsSeeder`

### 3.9 Admin Panel Extension & Security Hardening ✅ (2026-06-06)

Work added after the Phase 3 milestone — extends the admin panel with RBAC, security controls, and UI polish required before Phase 4 work begins.

#### Property Resource V2
- [x] `EditPropertyV2` + `ViewPropertyV2` pages — replace the original flat Edit/View with a tabbed layout; set as default edit/view routes on `PropertyResource`
- [x] `PropertyFormV2` — five tabs: Details (core fields), Listings (listing records inline), Amenities (pivot checkbox grid in 2-column sections), Media (photo management placeholder), Access (gate code / access info — encrypted field, service-mediated)
- [x] `PropertyInfolistV2` — structured detail view with amenity pill badges per category
- [x] `PropertyAmenityResource` — full CRUD for the `property_amenities` table; allows adding custom amenities beyond the 26 seeded defaults; Add Amenity button in toolbar; navigation group: Marketplace

#### Admin RBAC & User Management
- [x] `App\Support\AdminAuth` — semantic role-check helper class; methods: `canManageProperties()`, `canManageSecurity()`, `canManagePlatformContent()`, `canManageArticles()`, `canManageSystem()`, `isSuperAdmin()`; all Filament `canAccess()` gates use this class — never raw role strings
- [x] Admin roles defined: `super_admin`, `global_admin`, `property_admin`, `security_admin`, `article_admin`, `staff`
- [x] `AdminUserResource` — full CRUD for admin users; `CheckboxList` for multi-role assignment (filtered to admin roles only); `getEloquentQuery()` scoped to admin-role users only — non-admin users never appear in this list; navigation group: Users & Access
- [x] `CreateAdminUser` page — custom `handleRecordCreation()` writes to `users` + cross-DB `UserProfile`; `Hash::make()` for password; `AuditService` event on create; `email_verified_at` auto-set (admin-created accounts skip email verification by design)
- [x] `EditAdminUser` page — custom `handleRecordUpdate()` only re-hashes password when a new value is provided; self-deletion prevented (`id !== Auth::id()` check); delete action visible to super_admin only; `AuditService` event on update
- [x] Add Admin User button in toolbar; bulk delete gated to super_admin only

#### IP Allowlist
- [x] `EnsureAdminIpAllowed` middleware — registered in `AdminPanelProvider`; reads IP list directly from DB (bypasses Valkey so changes take immediate effect); supports individual IPs and CIDR ranges; fails open on DB outage; `APP_ENV=local` always bypasses; `config('platform.admin_ip_bypass_ip')` server-level escape hatch; empty list = allow all
- [x] `IpAllowlistSettings` Filament page — two-form layout: IP Allowlist block (IP / CIDR entries) and Emergency Bypass IP block (plain IPs, multiple); each block has its own toolbar with an Add button; regex validation on all inputs; changes take effect immediately (no cache); navigation group: Users & Access
- [x] `AdminIpAllowlist` Artisan command — CLI escape hatch; `--add`, `--remove`, `--list` subcommands; bypasses Filament entirely for locked-out recovery
- [x] `config/platform.php` — `admin_ip_bypass_ip` key added so `ADMIN_IP_BYPASS_IP` env var survives `php artisan config:cache` in production

#### Audit Log Viewer
- [x] `AuditLogViewer` Filament page — reads DB 9 `audit_log` via `AuditLog` model; filterable by `event_type` and `source_database`; paginated (25/50/100); no edit or delete actions; read-only (`recordUrl(null)`); gated to `canManageSecurity()`; navigation group: Users & Access

#### CMS Settings Pages
- [x] `HomepageSettings` — 29 tenant settings covering site logo, topbar strip, hero copy, hero stats, platform stats block, section visibility toggles, CTA content; seeded via `HomepageSettingsSeeder`
- [x] `NavigationSettings` — main nav links repeater (label / URL / enabled), CTA button, signin link; all driven by `TenantService` / DB 12 `tenant_settings`
- [x] `LoginPageSettings` — unauthorized use notice text, two configurable policy links with URL validation; navigation group: System
- [x] All settings pages: `getHeaderActions()` returns `[]` (no top-right Save button); blade has bottom-aligned Save Changes button with consistent spacing

#### Admin UI & Theming
- [x] `HasIconPageHeading` trait — reusable trait for all Filament pages; injects SVG heroicon inline with page title at 2rem / `#C84C21` brand color; used on every admin page
- [x] Admin login page — AH dark logo (`#0A1512`), input fields styled to match public site design system, Sign In button fixed at 44px height to prevent resize when spinner appears on submit
- [x] AH corner brackets — `::before` / `::after` on `.ah-admin-mark-wrap` wrapper; color `#0A1512`; appear on the admin topbar and login page logo area
- [x] Toolbar button standard — all Create/Add actions placed in `toolbarActions()` (never `getHeaderActions()`); label format: "Add [Thing]"; documented in `CLAUDE.md` as a non-negotiable convention
- [x] Vendor blade override — `resources/views/vendor/filament-tables/index.blade.php` — centers toolbar content and removes default `justify-content: space-between`

#### Security Hardening
- [x] `docs/security.md` created — full findings register with severity levels, fixed/open/deferred status, and architectural security decisions table
- [x] SEC-024 (Trusted Proxies — HIGH) documented; must be configured before IP allowlist is relied on in production
- [x] SEC-025 (Role changes not audited — MEDIUM) documented as open
- [x] Prior findings SEC-001 through SEC-023 documented with fix status

### Phase 3.9 Milestone ✅ (2026-06-06)

- [x] Admin users can be created, edited, and assigned multiple roles; only super_admin can delete
- [x] IP allowlist blocks non-listed IPs on the admin panel; emergency bypass and CLI recovery path both tested
- [x] Audit log viewer displays all DB 9 events, read-only, paginated, filterable
- [x] All three CMS settings pages (Homepage, Navigation, Login Page) save to DB 12 and reflect on the public site
- [x] Every admin page has a branded icon heading and consistent Save button placement
- [x] Security findings documented in `docs/security.md`; no open HIGH findings in code (SEC-024 is a deployment-time config task)
- [x] **MILESTONE: Admin panel production-ready for internal use**

---

## PHASE 3.10 — Admin: Platform User Management ✅ COMPLETE (2026-06-10)

The goal: a full admin resource for managing all non-staff platform users (hunters, landowners, club admins, outfitters, consultants, sellers). Extends the Phase 3.9 admin panel with the customer-facing user management tools needed before lease work progresses.

### 3.10.1 Schema Additions

- [x] `property_managers` migration (DB 2) — tracks co-owners, managers, operators per property; role CHECK (`co_owner`, `manager`, `operator`); unique partial index on active grants; `PropertyService::canManageProperty()` is the authority gate
- [x] `user_admin_notes` migration (DB 1) — append-only staff notes per user; `(id, user_id, author_user_id, note, created_at)` — no `updated_at`, no `deleted_at`

### 3.10.2 Models

- [x] `App\Models\Property\PropertyManager` — `property` connection; `scopeActive()`; cross-DB `getUser()` via UserService
- [x] `App\Models\Identity\UserAdminNote` — `identity` connection; `ImmutableModel` pattern (append-only); `scopeForUser($userId)`

### 3.10.3 Admin Resource

- [x] `AdminAuth::canManageUsers()` — new gate method; `security_admin`, `global_admin`, `super_admin`
- [x] `CustomerUserResource` — List/View/Edit; scoped to non-admin account types only
  - **List page** — name/email, account_type badge, status badge, trust score (color-coded), last login, registered date; filters: account_type / status / state; searchable by name + email
  - **Edit page — Identity tab** — avatar upload (→ DocumentService → `user_profiles.avatar_document_id`), name, email, phone, account_type (primary portal routing), status; `user_roles` CheckboxList
  - **Edit page — Profile tab** — bio, state, zip, DOB, gender; veteran + first responder toggles with conditional branch/rank/service-range/bio fields
  - **Edit page — Security tab** — Public Profile toggle, username (super_admin only), Force Password Reset button, Admin Set Password (super_admin only), MFA status display + enable/disable per method + Clear TOTP Token + Disable All MFA buttons, Login History last 20
  - **Edit page — Compliance tab** — trust score + event history, background check status, OFAC latest result, identity verifications list
  - **Edit page — Admin Notes tab** — append-only staff notes list; new note textarea (saved on form submit → `user_admin_notes`)
  - **Edit page — Audit Log tab** — read-only DB 9 events scoped to this user_id with before/after diff rows
  - **Edit page — Properties & Leases tab** — properties owned (direct + via PropertyManager), property manager/operator roles, leases as lessor/lessee, club ownership + memberships (all cross-DB)
  - **View page** — infolist: Identity, Profile, Platform Roles, MFA Status with badge per factor; header actions: Edit / Reset MFA / Disable TOTP / Disable Email OTP / Disable SMS / Regenerate Recovery Codes / Revoke All Tokens / Suspend / Ban
  - **Header actions (Edit page):** Suspend / Unsuspend / Ban (super_admin only) with audit log writes

### Phase 3.10 Milestone ✅ (2026-06-10)

- [x] Admin can view, search, and filter all non-staff platform users
- [x] Admin can edit identity, profile, veteran/first-responder detail, and account status
- [x] MFA methods can be enabled, disabled, and reset per-user from the Security tab
- [x] Admin Notes are append-only and visible to all staff
- [x] Audit log tab shows per-user event history with field-level diffs
- [x] Properties, leases, and club memberships visible cross-DB on the Properties & Leases tab
- [x] **MILESTONE: Full platform user management operational**

---

## PHASE 4 — Lease Lifecycle

The goal: the full lease pipeline — application, negotiation, approval, e-signature, and activation.

### 4.1 DB 3 Lease Migrations ✅ (2026-06-07)

- [x] `lease_applications` — application records with status tracking, encrypted `message` field (pgp_sym_encrypt)
- [x] `lease_application_hunters` — per-hunter PII with 12 encrypted fields (contact, address, emergency contact, medical, licensing)
- [x] `lease_application_messages` — applicant/landowner/admin message thread on each application
- [x] `lease_application_review_history` — append-only review action log per application
- [x] `leases` — active lease records; status enum (pending_signatures → active → expired/terminated/cancelled)
- [x] `lease_hunters` — hunters authorized under a lease with role (primary/guest/member)
- [x] `lease_renewals` — renewal offer records with negotiated terms
- [x] `lease_notes` — internal and external notes per lease
- [x] `clubs` — club entity records with slug, membership fee, visibility
- [x] `club_members` — membership roster with role (owner/admin/member) and status
- [x] `club_leases` — pivot linking clubs to leases
- [x] `check_ins` — append-only GPS check-in/check-out log per hunter per visit
- [x] `signature_events` — append-only e-signature audit trail (permanent — never deleted)
- [x] `esignature_requests` — Dropbox Sign envelope tracking per lease
- [x] RLS policies on `leases`, `lease_hunters`, `check_ins`, `lease_notes`
- [x] PII encryption migration applied — `message` on `lease_applications`, 12 fields on `lease_application_hunters`
- [x] 14 Eloquent models under `App\Models\Lease\`: `Lease`, `LeaseApplication`, `LeaseApplicationHunter`, `LeaseApplicationMessage`, `LeaseApplicationReviewHistory`, `LeaseHunter`, `LeaseRenewal`, `LeaseNote`, `Club`, `ClubMember`, `ClubLease`, `CheckIn`, `SignatureEvent`, `EsignatureRequest`
- [x] `php artisan migrate:single lease` — 16/16 zero errors; all 15 tables verified in `ah_lease`

### 4.2 DB 11 Documents Migrations ✅ (2026-06-07)

- [x] `documents` — file metadata (storage_key, storage_bucket, storage_provider, status, mime_type, file_size, virus_scan_result)
- [x] `document_thumbnails` — photo/video thumbnail variants per document
- [x] `esignature_requests` — Dropbox Sign envelope records with status, signer list, completed PDF reference
- [x] `esignature_signers` — per-signer status and timestamps within a request
- [x] `qr_codes` — generated QR code records (check-in, property access, harvest report) with scan log
- [x] `print_jobs` — queued PDF print job records (lease agreement, harvest report, property map, field guide)
- [x] All 6 Eloquent models created: `Document`, `DocumentThumbnail`, `EsignatureRequest`, `EsignatureSigner`, `QrCode`, `PrintJob` under `App\Models\Documents\`
- [x] `php artisan migrate:single documents` — 6/6 zero errors; all tables verified in `ah_documents`

### 4.3 Lease and Document Services ✅ (2026-06-10)

- [x] `App\Services\Lease\LeaseService` — read (lease detail with cross-DB assembly, active leases by lessee/lessor), writes (create from application, activate, terminate, expire), Valkey caching
- [x] `App\Services\Lease\ApplicationService` — submit (with hunter PII snapshots), approve, reject, override, withdraw, listing snapshot denormalization, audit logging
- [x] `App\Services\Lease\ApplicationMessageService` — send, read, mark read, unread count, email notification dispatch
- [x] `App\Services\Documents\DocumentService` — register, store uploaded file, mark ready/quarantined, soft delete, QR code create and token resolution
- [x] `LeaseDetailDTO` — cross-DB assembly DTO
- [x] All lease models: `Lease`, `LeaseApplication`, `LeaseApplicationHunter`, `LeaseApplicationMessage`, `LeaseApplicationReviewHistory`, `LeaseHunter`, `LeaseRenewal`, `LeaseNote`, `Club`, `ClubMember`, `ClubLease`, `CheckIn`, `SignatureEvent`, `EsignatureRequest`
- [x] `App\Services\Lease\EsignatureService` — **in-platform signing** (not Dropbox Sign); creates `EsignatureRequest` + `EsignatureSigner` records in DB 11; records permanent `SignatureEvent` events in DB 3; activates lease on final signature via `activateIfComplete()`; Dropbox Sign path added in Phase 4.5.5 for custom contracts
- [x] `App\Services\Lease\CheckInService` — QR check-in/out + advisory GPS validation (built in 4.6); overdue detection deferred to the hunt-party safety phase
- [ ] `App\Services\Documents\QrCodeService` — standalone QR service; QR code logic currently in DocumentService — **evaluate whether dedicated service is needed**

### 4.4 Lease Application Flow (Customer Portal `/apply`) ✅ (2026-06-07)

- [x] `GET /apply/my-applications` — application dashboard; lists all applications with status badges
- [x] `GET /apply/{listing}` — application form; pre-fills primary hunter from `HunterCredentials`, supports adding guest hunters
- [x] `POST /apply/{listing}` — submit application; processes hunter PII, DL + hunting license photo uploads via `DocumentService`, upserts `HunterCredentials` for primary hunter, saves guest hunters via `GuestHunterService`, delegates to `ApplicationService::submit()`
- [x] `GET /apply/status/{application}` — application status page; shows current status, hunter list, message thread, rejection reason
- [x] `POST /apply/status/{application}/message` — applicant sends message; rate-limited `throttle:10,1`; delegates to `ApplicationMessageService`
- [x] `Apply/Index.tsx` (923 lines) — full hunter PII form with DL/license upload, guest hunter management, minor detection
- [x] `Apply/Status.tsx` (471 lines) — status page with message thread UI
- [x] `Apply/MyApplications.tsx` (186 lines) — application dashboard

### 4.5 Lease Approval & In-Platform E-Signature ✅ (2026-06-10)

- [x] `LeaseApplicationResource` — list + view pages; navigation group: Marketplace
- [x] **Approve action** — modal with start/end date, total price, sign-as-lessor checkbox, notify-applicant checkbox; creates `Lease`, `LeaseHunter`, calls `EsignatureService::createRequest()`, optionally records lessor signature immediately, sends signing link to lessee via `ApplicationMessageService`
- [x] **Reject action** — requires reason; optional applicant notification via message
- [x] **Override action** — available after approve/reject; records from/to status + reason in `lease_application_review_history`; optional applicant notification
- [x] **Sign as Lessor action** — visible when lease is `pending_signatures` and lessor has not yet signed; records in-platform signature, activates lease if lessee already signed
- [x] **Send Message action** — posts to application message thread; applicant receives email notification
- [x] **Edit Notes action** — saves internal staff notes (not visible to applicant) via `ApplicationMessageService::saveNotes()`
- [x] **View page sections** — Application Details, Listing & Applicant sidebar, Lease & Signing Status (signer rows with timestamps), Hunter Roster (full PII including DL + hunting license), Communications thread, Review History timeline, Review metadata
- [x] `EsignatureService::createRequest()` — provider `in_platform`; creates `EsignatureRequest` + two `EsignatureSigner` rows (lessor order 1, lessee order 2); writes `SignatureEvent` (sent)
- [x] `EsignatureService::recordSignature()` — marks signer signed, writes `SignatureEvent` (signed), calls `activateIfComplete()` on all-signed
- [x] `activateIfComplete()` — marks request completed, writes `SignatureEvent` (completed), calls `LeaseService::activate()`, approves primary `LeaseHunter`, writes audit event

---

### 4.5.5 Custom Lease Contracts — Ranch+ Tier (Dropbox Sign)

The goal: landowners on **Ranch or Estate** tier can attach a custom PDF contract (e.g., attorney-drafted) to a lease at approval time. The platform sends it via Dropbox Sign for embedded in-browser signing. On completion, the signed PDF is downloaded and stored in `ah-documents`. The in-platform signing flow (Phase 4.5) is unchanged for standard leases.

#### Design decisions (resolved 2026-06-10)

- **Upload timing:** Approval-time — admin attaches the custom PDF when approving an application in `ViewLeaseApplication`, not at the listing level
- **Signing experience:** Embedded — lessee signs inside the member portal without leaving the site
- **Signed PDF storage:** Yes — downloaded from Dropbox Sign after completion and stored in `ah-documents` bucket; `EsignatureRequest.signed_document_id` updated
- **Local dev webhook testing:** Artisan simulation command (`php artisan dropboxsign:simulate {lease_id}`) fires the webhook payload in-process against the webhook controller with a valid HMAC. ngrok used once before launch for real end-to-end validation only.

#### DB changes

- [ ] `property_listings.custom_contract_document_id UUID NULL` — migration (DB 2); references DB 11 `documents.id`; NULL = in-platform signing; NOT NULL = Dropbox Sign with this PDF. **Note:** column added at listing level for future use even though upload is at approval time — admin sets it during approval, not the landowner.
- [ ] Verify `esignature_requests.external_envelope_id` column exists in DB 11 migration — stores Dropbox Sign `signature_request_id` for webhook lookup
- [ ] DB 12 entitlement seed migration — `custom_lease_template` (boolean `true`) added to Ranch and Estate plan versions

#### New constants

- [ ] `Entitlements::CUSTOM_LEASE_TEMPLATE = 'custom_lease_template'` added to `app/Support/Entitlements.php`

#### New files

- [ ] `app/Services/Lease/DropboxSignService.php` — Dropbox Sign API wrapper
  - `createEmbeddedEnvelope(Document $pdf, array $lessor, array $lessee): array` — POST `/v3/signature_request/send_with_reusable_form` or `/v3/signature_request/send`; returns `signature_request_id` + per-signer embedded signing URLs
  - `getEmbeddedSigningUrl(string $signatureId): string` — GET `/v3/embedded/sign_url/{id}`; used to generate lessee's in-browser signing URL
  - `downloadSignedPdf(string $signatureRequestId): string` — GET `/v3/signature_request/{id}/files`; returns raw PDF bytes
  - `verifyWebhookSignature(string $payload, string $headerSig): bool` — HMAC-SHA256 verification using `DROPBOX_SIGN_WEBHOOK_SECRET`
- [ ] `app/Http/Controllers/Api/DropboxSignWebhookController.php`
  - `handle(Request $request): Response` — verifies HMAC; dispatches `ProcessDropboxSignWebhook` job on `priority` queue; returns 200 immediately
- [ ] `app/Jobs/Lease/ProcessDropboxSignWebhook.php` — `priority` queue; extracts `signature_request_id`; looks up `EsignatureRequest` by `external_envelope_id`; marks all signers `signed`; calls `DropboxSignService::downloadSignedPdf()` → `DocumentService::storeRawFile()` → updates `EsignatureRequest.signed_document_id`; calls `activateIfComplete()`
- [ ] `app/Console/Commands/DropboxSignSimulate.php` — dev-only Artisan command; builds `signature_request_all_signed` payload; generates valid HMAC; calls `DropboxSignWebhookController::handle()` in-process; `DropboxSignService::downloadSignedPdf()` returns stub PDF in non-production environments

#### Modified files

- [ ] `app/Services/Lease/EsignatureService.php` — `createRequest()` accepts optional `Document $customPdf`; if provided AND lessor has `custom_lease_template` entitlement → calls `DropboxSignService::createEmbeddedEnvelope()`; stores `provider = 'dropbox_sign'`, `external_envelope_id = signature_request_id`; otherwise falls through to existing in-platform path
- [ ] `app/Filament/Admin/Resources/Applications/Pages/ViewLeaseApplication.php` — Approve action form gains optional `FileUpload::make('custom_contract_pdf')` field (visible only when `EntitlementService::can($lessor, CUSTOM_LEASE_TEMPLATE)`); uploaded file passed to `EsignatureService::createRequest()`
- [ ] `routes/api.php` — `POST /api/webhooks/dropbox-sign` — no auth middleware; HMAC-verified internally
- [ ] `.env.example` — add `DROPBOX_SIGN_API_KEY`, `DROPBOX_SIGN_WEBHOOK_SECRET`, `DROPBOX_SIGN_TEST_MODE=true`

#### Signing flow (custom contract path)

```
1. Admin approves application in ViewLeaseApplication
   → uploads custom PDF in Approve modal (Ranch+ lessor only)
   → EsignatureService::createRequest() detects custom PDF
   → DropboxSignService::createEmbeddedEnvelope() → Dropbox Sign API
   → EsignatureRequest created: provider=dropbox_sign, external_envelope_id=ds_request_id
   → EsignatureSigner rows created with embedded_signing_url per signer

2. Lessee visits /member/leases/{id}/sign
   → EsignatureService::getEmbeddedSigningUrl($lessee)
   → DropboxSignService::getEmbeddedSigningUrl($signatureId) → short-lived URL
   → Lessee signs in embedded iframe — no redirect away from platform

3. Both parties sign → Dropbox Sign fires webhook POST /api/webhooks/dropbox-sign
   → HMAC verified
   → ProcessDropboxSignWebhook dispatched on priority queue
   → Signed PDF downloaded → stored in ah-documents → signed_document_id updated
   → EsignatureRequest.status = completed
   → activateIfComplete() → lease ACTIVE

4. Admin sees ACTIVE in LeaseApplicationResource — identical to in-platform path
```

#### Milestone checklist

- [ ] Ranch+ landowner can have admin attach a custom PDF when approving their application
- [ ] Both parties receive embedded signing experience inside the platform (no redirect to Dropbox Sign)
- [ ] Lease activates after final signature via webhook
- [ ] Signed PDF stored in `ah-documents` and accessible via "Download Signed Contract" button
- [ ] `dropboxsign:simulate` command fires the full webhook flow locally without ngrok
- [ ] Standard in-platform signing for Homestead-tier leases is completely unaffected
- [ ] Commit: "Custom lease contracts via Dropbox Sign (Ranch+ entitlement)"

### 4.6 Member Portal — Lease Dashboard (`/member`)

- [x] `GET /member` — member portal home; active leases, expiry countdown, checked-in banner
- [x] `GET /member/leases/{id}` — individual lease view; documents, rules, access info (gate code decrypt on authorized lessee only), Field Access card (check-in, gate QR, stand-map link, owner email-QR)
- [x] Check-in — `POST /member/checkin` + `/member/checkout`; advisory GPS, validates lessee/approved-hunter on active lease via `CheckInService`
- [x] `GET /member/leases/{id}/stands` — Mapbox stand map (boundary + stands from DB 13, member-only GPS per SEC-024)
- [x] QR check-in page — `/checkin/{token}` — works without login; prompts login if not authenticated; per-property gate QR (get-or-create at lease activation), served as PNG and emailable (member owner + admin Filament)
- [x] Commit: "Member portal check-in system + stand map (Phase 4.6)"

### 4.7 Lease PDF and QR Code Generation Jobs

- [ ] `App\Jobs\Documents\GenerateLeasePdf` — default queue; uses template + merge fields; stores in `ah-documents` bucket
- [ ] `App\Jobs\Documents\GenerateQrCode` — default queue; QR encodes property UUID + type; stores metadata in DB 11
- [ ] `App\Jobs\Documents\ScanUploadedFile` — virus scan via ClamAV; updates `documents.status` on pass/fail; moves to quarantine on fail
- [ ] Commit: "Document generation and scan jobs"

### Phase 4 Milestone

- [x] A hunter can browse, apply for a listed property, and see their application status
- [x] Admin can review the application, approve it, and trigger in-platform e-signature
- [x] On final in-platform signature, lease activates automatically
- [ ] Ranch+ landowner can use a custom PDF contract signed via Dropbox Sign (Phase 4.5.5)
- [x] Member portal shows the active lease with lease details and gate code
- [x] Gate code is visible in member portal only to the active lessee (encrypted, decrypted by service)
- [x] QR check-in works and logs entry to DB 3 `check_ins`
- [ ] All events in DB 9 audit log
- [ ] **MILESTONE: Full lease lifecycle functional end-to-end**

## PHASE 4.8 — Admin Security: Azure SSO (Admin Panel Only)

The goal: Azure Entra ID (Azure AD) SSO for the `/admin` panel, gated behind the `saml_sso` feature flag. Customer/member portals are unaffected.

- [ ] **Azure App Registration** — create App Registration in Microsoft Entra ID; configure redirect URI (`/admin/auth/azure/callback`); note Tenant ID, Client ID, Client Secret
- [ ] **Package install** — add `laravel/socialite` + `socialiteproviders/microsoft-azure`; wire credentials into `.env` and `config/services.php`
- [ ] **Admin-scoped OAuth routes** — `GET /admin/auth/azure` (redirect) and `GET /admin/auth/azure/callback` (handler); scoped to the Filament admin panel only
- [ ] **Login page integration** — extend `AdminPanelProvider` login to show "Sign in with Microsoft" button when `saml_sso` flag is enabled; hide email/password form when SSO-only mode is active
- [ ] **Callback handler** — receive Azure token; extract email and claims; look up matching `users` record in Identity DB by email; enforce `staff` or `super_admin` role requirement; reject if no matching Identity DB user exists
- [ ] **Session handoff** — on successful SSO, create Laravel session via standard `Auth::login()` flow; session stored in Valkey Cluster 1 (existing session store)
- [ ] **Feature flag gate** — entire SSO flow behind `feature('saml_sso')` flag; toggle in admin Feature Flags page to enable/disable without a deploy
- [ ] **Audit log** — SSO login events written to DB 9 `audit_log` with event_type `sso_login`, provider `azure`, and user details
- [ ] **LoginPageSettings extension** — add SSO mode toggle (SSO-only vs SSO+password) to the Login Page Settings admin page
- [ ] Commit: "Azure SSO for admin panel (Phase 4.8)"

### Phase 4.8 Decision Points (resolve before building)
- **Match-only vs JIT provisioning**: Recommended — match-only (user must exist in Identity DB). JIT adds complexity and risk.
- **SSO-only vs SSO+password fallback**: Recommended — SSO primary, password fallback controlled by `saml_sso` flag setting.
- **Which Azure tenant(s)**: Must be configured in `.env`; single tenant recommended for admin access.

---

## PHASE 4.9 — Member Portal: Multi-Template Profile System

**Goal:** Every user can hold one or more profile types simultaneously. Each type has its own layout, custom attributes, and URL. A user can set a default profile type (e.g., a hunter who later posts a lease switches their default to Land Owner without losing their hunter profile). All templates share a global design system and CSS tokens; template-specific layout is implemented per type.

**Full version for each template:** profile editing, avatar upload, and all type-specific fields.

---

### Core Architecture

**User profile types are stored in DB 1.** A new `user_profile_types` join table tracks which profile types each user has active. A `default_profile_type` column on `users` controls which template renders by default at `/member/profile`.

**DB 1 additions needed:**
- `user_profile_types` — `(user_id, profile_type, created_at)` — one row per active type per user
- `users.default_profile_type` — enum or string column; defaults to `hunter` on registration

**Avatar upload** → `DocumentService` → stores file in `ah-documents` bucket → writes metadata to DB 11 `documents` → `user_profiles.avatar_document_id` updated.

**URL pattern:**
- `GET /member/profile` — renders the user's default profile template
- `GET /member/profile/{type}` — renders a specific template (e.g., `/member/profile/outfitter`)
- `POST /member/profile/default` — switches the user's default profile type
- `POST /member/profile/{type}` — saves edits for a specific template

**Shared global CSS:** Typography, color tokens, card/section styles, form controls, avatar component, and upload widget are shared via a `ProfileLayout` wrapper component. Each template imports the wrapper and adds its own sections.

---

### Profile Templates

#### 4.9.1 — Hunter Profile
- Layout: TBD — design reference image to be provided before build
- Core fields: display name, avatar, bio, state, hunting style/species preferences (from `user_profiles.hunting_profile` JSONB), years hunting, preferred terrain, trophy preferences, public/private visibility toggle
- Veteran and first responder callouts (read-only on profile, edit via account settings)
- Public URL: `/hunters/{username}` (future — Phase 7+)

#### 4.9.2 — Outfitter / Guide Profile
- Core fields: display name, avatar, business name, license number, operating states, species offered, guide style (fly fishing, waterfowl, big game, etc.), pricing range, booking contact, bio
- Connects to `outfitter_profiles` (DB 6) when Commerce module is active
- Public-facing listing card (used in outfitter directory)

#### 4.9.3 — Land Owner / Operator Profile
- Core fields: display name, avatar, bio, operating states, total acres managed, property types (farm, timber, ranch, etc.), contact preference
- Links to their owned/managed properties in DB 2
- Landowner credibility indicators: years on platform, total leases executed, ratings

#### 4.9.4 — Land / Lease Consultant Profile
- Core fields: display name, avatar, credentials, license numbers, service area (states), specialty (lease negotiation, boundary surveys, wildlife management plans, etc.), bio, contact
- Connects to `consultant_profiles` (DB 6) when consulting marketplace is active
- Gated behind `consulting_marketplace` feature flag

#### 4.9.5 — Seller Profile
- Core fields: display name, avatar, business name, product categories, seller rating, bio, contact
- Connects to `marketplace_listings` (DB 6)
- Gated behind `equipment_marketplace` feature flag

#### 4.9.6 — Advertiser Profile
- Core fields: organization name, logo/avatar, contact name, campaign types, operating states, bio
- Connects to `ad_campaigns` (DB 12)

#### 4.9.7 — Corporate Account Profile
- Core fields: company name, logo/avatar, primary contact, industry, states of operation, employee count range, bio
- Maps to `account_type = corporate` on `users`
- Supports multiple sub-users under one corporate account (future — Phase 8+)

#### Future Expansion (do not build yet)
- **Fisherman** — similar to Hunter but freshwater/saltwater species, license tracking, body-of-water preferences
- **Charter Operator** — similar to Outfitter but marine-focused; vessel info, captain license, departure ports

---

### 4.9 Checklist (to be expanded when each template is designed)

- [ ] DB 1 migration: `user_profile_types` table + `users.default_profile_type` column
- [ ] `UserService::addProfileType()`, `setDefaultProfileType()`, `getActiveProfileTypes()`
- [ ] `ProfileLayout` shared React wrapper (avatar, nav, edit mode toggle, save action)
- [ ] Avatar upload endpoint + `DocumentService` wiring
- [ ] **4.9.1** Hunter profile template (design reference image on file before build)
- [ ] **4.9.2** Outfitter / Guide profile template
- [ ] **4.9.3** Land Owner / Operator profile template
- [ ] **4.9.4** Land / Lease Consultant profile template (behind `consulting_marketplace` flag)
- [ ] **4.9.5** Seller profile template (behind `equipment_marketplace` flag)
- [ ] **4.9.6** Advertiser profile template
- [ ] **4.9.7** Corporate Account profile template
- [ ] Default profile switcher UI in member portal nav
- [ ] `GET /member/profile` and `GET /member/profile/{type}` routes
- [ ] Commit: "Multi-template profile system (Phase 4.9)"

### 4.9 Decision Points (resolve before building)
- **Public vs private profiles**: Should profiles be publicly accessible at `/hunters/{username}` from the start, or member-portal-only until a public directory phase?
- **Type activation**: Does a user self-select profile types during onboarding, or are types activated automatically based on account actions (e.g., posting a property auto-activates Land Owner)?
- **Type-specific onboarding**: Does activating a new profile type trigger a guided setup flow, or is it immediate with an empty edit form?

---

## PHASE 5 — Billing & Payments

The goal: Stripe payment collection, Stripe Connect landowner payouts, subscription management, and tax handling all working.

### 5.1 DB 4 Billing Migrations

- [ ] `subscriptions` — Stripe subscription records with `plan_version_id` and `active_promotion_claim_id` columns
- [ ] `invoices` — per-billing-cycle invoice records
- [ ] `payments` — individual Stripe charge records (stripe_charge_id, last_four, brand — no raw card data ever)
- [ ] `payment_methods` — saved payment method metadata (Stripe token only)
- [ ] `stripe_connect_accounts` — landowner/outfitter/consultant payout accounts
- [ ] `payouts` — payout records (lease revenue disbursements to landowners)
- [ ] `w9_records` — W-9 data (TIN encrypted at rest via pgcrypto)
- [ ] `promo_codes` — code definitions with usage tracking
- [ ] `promotion_claims` — per-user promo claim records (from `pricing_schema_additions.md`)
- [ ] `security_deposits` — held deposit records with escrow status
- [ ] `tax_nexus_tracking` — state-by-state economic nexus thresholds for TaxJar
- [ ] `tax_1099_records` — generated 1099 tracking (year, recipient, amount, filing status)
- [ ] `php artisan migrate:single billing --fresh` — zero errors
- [ ] Commit: "DB 4 Billing migrations"

### 5.2 Billing Services

- [ ] `App\Services\Billing\BillingService` — subscription creation, upgrade/downgrade, cancellation, promo claim application; invalidates Valkey entitlement cache on any change
- [ ] `App\Services\Billing\StripeService` — Payment Intent creation, Stripe webhook verification and routing
- [ ] `App\Services\Billing\PayoutService` — Stripe Connect disbursement scheduling, platform fee calculation per landowner tier
- [ ] `App\Services\Billing\SubscriptionService` — trial period management, plan version locking at subscription creation, grandfathering logic
- [ ] Billing models: `Subscription`, `Invoice`, `Payment`, `PaymentMethod`, `StripeConnectAccount`, `Payout`, `PromotionClaim`
- [ ] Commit: "Billing services"

### 5.3 Stripe Webhook Processing

- [ ] `POST /api/webhooks/stripe` — signature-verified endpoint; routes to jobs on `priority` queue
- [ ] `App\Jobs\Billing\ProcessStripeWebhook` — routes by event type: `payment_intent.succeeded`, `invoice.payment_failed`, `customer.subscription.updated`, `account.updated` (Connect)
- [ ] On `invoice.payment_failed`: grace period flag on subscription, notify user, schedule retry
- [ ] On `customer.subscription.updated`: invalidate entitlement cache, write audit event
- [ ] On `account.updated`: update `stripe_connect_accounts.charges_enabled` / `payouts_enabled`
- [ ] Commit: "Stripe webhook processing"

### 5.4 Subscription & Plan Selection Flow

- [ ] Pricing page at `/pricing` — reads from DB 12 via `EntitlementService` with 15-min Valkey cache; displays active promotions from `promotional_periods`
- [ ] Plan selection during signup flows — hunter, landowner, club, outfitter signup steps integrate plan choice
- [ ] Stripe Checkout session creation for initial subscription
- [ ] Founding Landowner promo auto-application logic in `BillingService`
- [ ] Landowner Honeymoon auto-application on first listing publish
- [ ] `App\Jobs\Billing\ExpirePromotionClaims` — daily job; checks `promotion_claims.expires_at`; transitions to paid or downgrades per `on_expiration` setting; sends expiry warning emails at 30d / 7d / 1d
- [ ] Commit: "Subscription flows and promotion application"

### 5.5 Stripe Connect — Landowner Payouts

- [ ] Landowner Stripe Connect Express onboarding — creates account, tracks `charges_enabled` and `payouts_enabled`
- [ ] `App\Jobs\Billing\DisburseLandowernPayout` — default queue; calculates platform fee per landowner's plan_version; transfers net amount via Stripe Connect
- [ ] Payout schedule — triggered after lease payment clears (configurable hold period, default 7 days)
- [ ] `App\Jobs\Billing\Generate1099` — default queue; runs each January for qualifying recipients; files via Tax1099 API; stores record in `tax_1099_records`
- [ ] TaxJar integration — `App\Services\Billing\TaxService` — calculates sales tax on marketplace transactions at checkout
- [ ] Commit: "Stripe Connect payouts and tax integrations"

### 5.6 Admin Billing (Filament)

- [ ] `MembershipPlanResource` — admin CRUD for plans and plan version management; creating a new version is the only way to change pricing (immutable existing versions)
- [ ] `FeatureEntitlementResource` — per-plan entitlement key/value editor
- [ ] `PromotionalPeriodResource` — full promotion wizard (type, target, benefit, limits, display)
- [ ] `PromoCodeResource` — code generation with uniqueness guarantee
- [ ] `InvoiceResource` and `PaymentResource` — read-only billing oversight
- [ ] `PayoutResource` — payout history and status tracking
- [ ] Commit: "Filament billing and pricing admin"

### Phase 5 Milestone

- [ ] A hunter can select a plan and pay via Stripe Checkout
- [ ] A landowner can complete Stripe Connect onboarding and receive payouts after a lease is signed
- [ ] Founding Landowner promo auto-applies to qualifying signups
- [ ] Landowner Honeymoon auto-applies on first listing publish
- [ ] `EntitlementService` returns correct features for paid vs. free users
- [ ] Stripe webhooks process correctly and update subscription state
- [ ] Plan version change creates a new version row; existing subscribers stay on original version
- [ ] Admin can configure pricing, entitlements, and promotions without a code deploy
- [ ] **MILESTONE: Full billing and payment pipeline functional**

---

## PHASE 6 — Wildlife & Field Operations

The goal: harvest logging, trail camera management, quota tracking, and CWD compliance working in the member portal.

### 6.1 DB 5 Wildlife Migrations

- [ ] `harvest_logs` — harvest records with species, date, time, GPS coords (DB 13 ref), weapon, weight, trophy score, photos (DB 11 ref), offline_synced_at
- [ ] `fishing_harvest_logs` — separate table for fishing (species, weight, length, catch_and_release)
- [ ] `wildlife_sightings` — trail cam and visual sighting entries with GPS and AI tag results
- [ ] `trail_cameras` — camera registration per property (model, cellular plan, battery status)
- [ ] `trail_camera_images` — image metadata with AI species tags and confidence scores
- [ ] `harvest_quotas` — per-species per-property quota definitions
- [ ] `cwd_acknowledgments` — member CWD zone acknowledgment records
- [ ] `species_seasons` — per-state per-species season dates and bag limits
- [ ] `trophies` — Boone & Crockett / SCI score entries linked to harvest_logs
- [ ] Offline sync support: `local_record_id` UUID on harvest_logs and sightings for PWA dedup
- [ ] `php artisan migrate:single wildlife --fresh` — zero errors
- [ ] Commit: "DB 5 Wildlife migrations"

### 6.2 Wildlife Services

- [ ] `App\Services\Wildlife\HarvestService` — harvest log CRUD; quota check before allowing; GPS point write to DB 13 `harvest_locations`; CWD zone check via `GeospatialService`
- [ ] `App\Services\Wildlife\QuotaService` — per-species quota enforcement; remaining quota calculation; hard-stop gate when quota exhausted
- [ ] `App\Services\Wildlife\CwdService` — CWD zone lookup from DB 13; acknowledgment recording; state-specific carcass movement rule display
- [ ] `App\Services\Wildlife\TrailCameraService` — camera registration, image pull scheduling, AI tag result storage
- [ ] Wildlife models: `HarvestLog`, `WildlifeSighting`, `TrailCamera`, `TrailCameraImage`, `HarvestQuota`, `SpeciesSeason`
- [ ] Commit: "Wildlife services"

### 6.3 Member Portal — Field Operations

- [ ] `GET /member/harvest` — harvest log list with species filters and quota display
- [ ] `POST /api/harvest` — submit harvest; quota check; GPS to DB 13; photo dispatch to scan + tag jobs; CWD acknowledgment required if in CWD zone; entitlement check for `full_harvest_log`
- [ ] `GET /member/harvest/new` — harvest log form with GPS autofill and offline-capable (Service Worker queues when offline)
- [ ] `GET /member/wildlife` — sighting log and trail camera feed
- [ ] `GET /member/cameras` — trail camera management; image grid; AI tag results; battery/connectivity status
- [ ] `GET /member/quota` — per-species quota tracker with remaining counts and visual progress bars
- [ ] Commit: "Member portal field operations"

### 6.4 Wildlife Jobs

- [ ] `App\Jobs\Wildlife\TagTrailCameraImage` — default queue; sends image to AI scoring endpoint; writes species + confidence back to `trail_camera_images`
- [ ] `App\Jobs\Wildlife\ScanHarvestPhoto` — default queue; virus scan on uploaded harvest photo; sets `documents.status` to `ready` on pass
- [ ] `App\Jobs\Wildlife\SyncTrailCameraFeed` — default queue; polls CuddeLink / Spartan / Stealth Cam APIs for new images; imports to `trail_camera_images`
- [ ] `App\Jobs\Wildlife\CheckQuotaAlerts` — daily; sends warnings to landowner when quota reaches 75% and 90%
- [ ] Commit: "Wildlife queue jobs"

### Phase 6 Milestone

- [ ] A hunter can log a harvest with GPS from the member portal
- [ ] Harvest is blocked if quota is exhausted for the species
- [ ] CWD acknowledgment is required before logging a harvest in a CWD zone
- [ ] Trail camera images appear in the member portal and are AI-tagged
- [ ] Harvest logging works offline (Service Worker queues and syncs on reconnect)
- [ ] Quota tracker displays correct remaining counts
- [ ] **MILESTONE: Field operations working, offline-capable**

---

## PHASE 7 — Communications & Safety

The goal: real-time in-platform messaging via Laravel Reverb, multi-channel notifications, the SOS system, and the incident management system.

### 7.1 DB 7 Communications Migrations

- [ ] `message_threads` — all thread types (direct, lease_room, hunt_party, club_channel, application, support, outfitter, consulting); includes `metadata JSONB`, `archived_at`, `read_only_at`
- [ ] `messages` — message content with `moderation_status`, `moderation_flags`, soft-delete (`deleted_at`) but records never hard-deleted
- [ ] `message_participants` — per-thread participant records with last_read_at
- [ ] `notifications` — in-app notification records per user per event
- [ ] `notification_preferences` — per-user per-event-type delivery channel preferences
- [ ] `sos_event_log` — SOS event records (permanent — never soft or hard deleted)
- [ ] `broadcast_messages` — property-wide emergency broadcast records
- [ ] `support_tickets` — support ticket records linked to message threads
- [ ] `discord_webhooks` — platform→Discord webhook endpoint configuration
- [ ] `php artisan migrate:single communications --fresh` — zero errors
- [ ] Commit: "DB 7 Communications migrations"

### 7.2 DB 10 Incidents Migrations

- [ ] `incident_reports` — structured incident reports (type enum, GPS, photos, witness info, timestamp_lock)
- [ ] `sos_incident_records` — permanent SOS incident records cross-referencing DB 7 `sos_event_log`
- [ ] `lease_disputes` — formal dispute records (category, evidence, status, outcome)
- [ ] `damage_claims` — property damage claim records linked to leases
- [ ] `moderation_cases` — content moderation case records
- [ ] `php artisan migrate:single incidents --fresh` — zero errors
- [ ] Commit: "DB 10 Incidents migrations"

### 7.3 Messaging Services & Reverb

- [ ] Laravel Reverb configured in `config/broadcasting.php` and `docker-compose.yml` (separate container, port 8080)
- [ ] `routes/channels.php` — channel authorization for all thread types (DM, lease, club, hunt party)
- [ ] `App\Services\Communications\MessageService` — send message, thread creation, participant validation, moderation pipeline (rate limit → content filter → attachment scan → link check)
- [ ] `App\Services\Communications\ThreadService` — auto-creates threads on lease activation, outfitter booking, hunt scheduling, club creation
- [ ] `App\Services\Communications\NotificationService` — dispatches push, email, SMS per user's `notification_preferences`; priority tiers (Critical always fires, Low is digest-only)
- [ ] Valkey Cluster 2: presence keys (`presence:{userId}`, `typing:{threadId}:{userId}`, `last_read:{userId}:{threadId}`) with short TTLs
- [ ] Messaging models: `MessageThread`, `Message`, `MessageParticipant`, `Notification`
- [ ] Commit: "Messaging services and Reverb configuration"

### 7.4 Member Portal — Messaging UI

- [ ] `GET /member/messages` — thread list (DM, lease rooms, clubs, hunt parties) using design system aesthetic (no bubbles, monospace headers, blaze accents)
- [ ] `GET /member/messages/{threadId}` — thread view with real-time Reverb updates, date dividers, role badges, trail cam share blocks
- [ ] `POST /api/messages` — send message; dispatches `App\Events\MessageSent` which Reverb broadcasts to subscribed clients
- [ ] Typing indicators via Reverb presence events + Valkey typing keys
- [ ] File attachment upload — routes through `DocumentService` (virus scan before delivery)
- [ ] `GET /member/notifications` — unified notification inbox (messages + platform events)
- [ ] Commit: "Messaging UI"

### 7.5 SOS System

- [ ] SOS button in member portal header and hunt party chat — one tap triggers
- [ ] `POST /api/sos` — writes to `sos_event_log` (permanent), creates `sos_incident_records`, writes GPS to DB 13 `sos_locations`, dispatches `App\Jobs\Communications\DispatchSosAlert` on `priority` queue
- [ ] `DispatchSosAlert` — parallel dispatch: push to emergency contacts, SMS via Twilio, opens SOS thread in DB 7 (type: `sos`, permanent), notifies admin
- [ ] SMS fallback path for offline SOS (Twilio short code — works without data connection)
- [ ] Check-in widget in hunt party chat — "I'm Safe" / "Extend" / "SOS"; overdue detection triggers escalation cascade
- [ ] Hunt party overdue cascade: 6:00 PM soft notify → 6:05 PM notify party → 6:15 PM SMS emergency contact → 6:30 PM auto-SOS event
- [ ] Admin panel: active SOS events dashboard with GPS coordinates and property detail
- [ ] SOS records immutable: no `delete()` or `update()` ever called on `SosEventLog` or `SosIncidentRecord` models
- [ ] Commit: "SOS system"

### 7.6 Admin — Moderation & Support (Filament)

- [ ] `MessageModerationResource` — review queue for flagged messages; Approve / Hide / Delete / Warn Sender / Suspend Sender actions; all actions logged to DB 9
- [ ] `SupportTicketResource` — ticket queue with priority routing, SLA timers, staff assignment
- [ ] `IncidentResource` — incident reports by type; status workflow; evidence panel; one-click game warden contact
- [ ] `LeaseDisputeResource` — formal dispute workflow; evidence submission; outcome recording; Stripe refund trigger
- [ ] Commit: "Filament moderation, support, and incidents"

### 7.7 Notification Jobs

- [ ] `App\Jobs\Communications\SendEmailNotification` — default queue; renders template from DB 12 `notification_templates`; sends via Laravel Mail → Mailpit (dev) / SES (prod)
- [ ] `App\Jobs\Communications\SendSmsNotification` — default queue; Twilio API; logs to DB 7 `notifications`
- [ ] `App\Jobs\Communications\SendPushNotification` — default queue; Web Push VAPID for PWA
- [ ] `App\Jobs\Communications\DispatchSosAlert` — priority queue; retry_after 30s
- [ ] Commit: "Notification jobs"

### Phase 7 Milestone

- [ ] Two users can exchange real-time messages in a lease room via Reverb WebSocket
- [ ] Lease room auto-creates when a lease activates
- [ ] SOS button triggers the full dispatch cascade: push + SMS + permanent DB log + SOS thread
- [ ] Hunt party overdue detection escalates correctly
- [ ] Admin moderation queue shows flagged messages with action buttons
- [ ] Support tickets route to staff and receive replies
- [ ] Notifications respect user delivery preferences per event type
- [ ] **MILESTONE: Real-time messaging and safety systems operational**

---

## PHASE 8 — Commerce (Auctions & Marketplace)

The goal: the auction bidding engine, equipment marketplace, outfitter booking, and consulting marketplace all functional and gated by feature flags.

### 8.1 DB 6 Commerce Migrations

- [ ] `auction_listings` — auction config (reserve, starting bid, increment, window, auto-extend, buy-it-now)
- [ ] `auction_bids` — bid history (bidder_user_id, amount, is_auto_bid, bid_time — timestamp_lock)
- [ ] `auction_watchers` — watchlist entries
- [ ] `marketplace_listings` — equipment and service listings with category, condition, price, fulfillment options
- [ ] `marketplace_orders` — buyer order records
- [ ] `marketplace_reviews` — post-purchase ratings
- [ ] `outfitter_profiles` — outfitter business info, license, insurance, rating
- [ ] `hunt_packages` — outfitter service packages (species, duration, group size, price, inclusions)
- [ ] `outfitter_bookings` — booking records with deposit/balance tracking
- [ ] `consultant_profiles` — consultant professional info, credentials, service area
- [ ] `consultant_services` — service catalog per consultant
- [ ] `consultant_engagements` — engagement records with escrow payment tracking
- [ ] `php artisan migrate:single commerce --fresh` — zero errors
- [ ] Commit: "DB 6 Commerce migrations"

### 8.2 Auction Service (Valkey Cluster 4)

- [ ] `App\Services\Commerce\AuctionService` — core bidding engine backed by Valkey Cluster 4 (`auction` connection) for live bid state
- [ ] Live bid state in Valkey: `auction:{id}:current_bid`, `auction:{id}:bid_lock`, `auction:{id}:countdown` — atomic Lua scripts for race-condition-safe bid placement
- [ ] Auto-bid (proxy bidding) — server-side auto-increment up to user's max
- [ ] Auto-extend — bid in final N minutes extends by Y minutes; written back to Valkey countdown
- [ ] `App\Jobs\Commerce\CloseAuction` — scheduled on `priority` queue when countdown reaches zero; captures pre-authorized payment, creates winner application, notifies all participants
- [ ] Real-time bid feed via Reverb broadcast event `AuctionBidPlaced` — all watchers receive live updates
- [ ] Shill bid detection — flag if same user_id appears in consecutive bids (configurable threshold)
- [ ] Auction behind `auction_module` feature flag
- [ ] Commit: "Auction bidding engine"

### 8.3 Marketplace & Outfitter Services

- [ ] `App\Services\Commerce\MarketplaceService` — listing CRUD, order processing, Stripe checkout, Stripe Connect payout to seller, TaxJar on physical goods
- [ ] `App\Services\Commerce\OutfitterService` — package management, availability calendar, booking + deposit flow, pre-trip checklist, post-trip review
- [ ] `App\Services\Commerce\ConsultingService` — service catalog, booking, Stripe escrow hold, deliverable upload, release on landowner approval
- [ ] Commerce models: `AuctionListing`, `AuctionBid`, `MarketplaceListing`, `MarketplaceOrder`, `OutfitterProfile`, `HuntPackage`, `OutfitterBooking`, `ConsultantProfile`, `ConsultantEngagement`
- [ ] Commit: "Marketplace and outfitter services"

### 8.4 Customer Portal Commerce Features

- [ ] `GET /apply/auctions` — public auction browser; live bid display via Reverb; countdown timers; buy-it-now
- [ ] `POST /api/auctions/{id}/bid` — place bid; `auction_module` feature flag check; Stripe pre-authorization
- [ ] `GET /apply/marketplace` — equipment and service listing browse with filters
- [ ] `GET /apply/outfitters` — outfitter directory with package browse and booking flow
- [ ] `GET /apply/consulting` — consulting marketplace with service catalog and booking flow
- [ ] Commit: "Customer portal commerce pages"

### 8.5 Admin Commerce (Filament)

- [ ] `AuctionResource` — listing management, live bid monitoring, manual close/extend, dispute tools
- [ ] `MarketplaceResource` — listing moderation, order oversight, commission reports
- [ ] `OutfitterResource` — profile verification, license/insurance review, booking oversight
- [ ] `ConsultantResource` — profile review, engagement tracking, escrow management
- [ ] Commit: "Filament commerce admin"

### Phase 8 Milestone

- [ ] An auction listing can be created and published
- [ ] Multiple users can place bids in real-time; current bid updates live via Reverb
- [ ] Auto-extend fires when a bid comes in during the final window
- [ ] Auction close captures payment and creates a lease application for the winner
- [ ] Marketplace listing can be created, purchased, and seller receives a Stripe Connect payout
- [ ] Outfitter booking flow: browse → select dates → deposit → confirmed booking → outfitter thread auto-created
- [ ] All commerce actions gated behind correct feature flags
- [ ] **MILESTONE: Full commerce layer functional (auctions, marketplace, outfitters, consulting)**

---

## PHASE 9 — Analytics, Audit & Reporting

The goal: all ETL pipelines running nightly, DB 9 audit log proven immutable, and the reporting suite accessible to landowners and admins.

### 9.1 DB 9 Audit Migrations

- [ ] `audit_log` — append-only log; columns: `id UUID`, `event_type`, `entity_type`, `entity_id UUID`, `actor_user_id UUID`, `delta JSONB`, `metadata JSONB`, `ip_address`, `created_at`
- [ ] PostgreSQL RULE: `CREATE RULE audit_no_update AS ON UPDATE TO audit_log DO INSTEAD NOTHING`
- [ ] PostgreSQL RULE: `CREATE RULE audit_no_delete AS ON DELETE TO audit_log DO INSTEAD NOTHING`
- [ ] Verify: `UPDATE audit_log SET ...` — no-ops at DB level AND `ImmutableModel` throws in application
- [ ] `audit_log` partitioned by `created_at` month for performance at scale
- [ ] `php artisan migrate:single audit --fresh` — zero errors, immutability verified
- [ ] Commit: "DB 9 Audit migrations with immutability rules"

### 9.2 DB 8 Analytics Migrations (ETL-only)

- [ ] `platform_daily_metrics` — MRR, ARR, active subscriptions, new signups, churn — one row per day
- [ ] `property_metrics` — per-property: listing views, applications, conversion rate, occupancy — per week
- [ ] `lease_metrics` — per-lease: application-to-sign duration, renewal rate — aggregated
- [ ] `harvest_metrics` — per-property per-species: harvest counts, quota utilization — per season
- [ ] `auction_metrics` — per-auction: bid count, final vs. reserve, winner conversion
- [ ] `user_cohort_metrics` — retention by acquisition cohort — per month
- [ ] `analytics` connection uses `readonly_user` credentials — any accidental write from app tier fails at DB auth level
- [ ] `php artisan migrate:single analytics --fresh` — zero errors
- [ ] Commit: "DB 8 Analytics migrations"

### 9.3 ETL Pipeline Jobs

All ETL jobs use the `analytics_etl` connection (write) and read from production databases via the standard read connections. They run on the `default` queue nightly via scheduler.

- [ ] `App\Jobs\Analytics\EtlPlatformDailyMetrics` — aggregates from DB 4 Billing; writes `platform_daily_metrics`
- [ ] `App\Jobs\Analytics\EtlPropertyMetrics` — aggregates from DB 2 Property + DB 3 Lease; writes `property_metrics`
- [ ] `App\Jobs\Analytics\EtlHarvestMetrics` — aggregates from DB 5 Wildlife; writes `harvest_metrics`
- [ ] `App\Jobs\Analytics\EtlAuctionMetrics` — aggregates from DB 6 Commerce; writes `auction_metrics`
- [ ] `App\Jobs\Analytics\EtlUserCohortMetrics` — aggregates from DB 1 + DB 4; writes `user_cohort_metrics`
- [ ] `App\Console\Commands\RunEtlPipeline` — Artisan command that runs all ETL jobs in dependency order; used by scheduler
- [ ] Scheduler in `routes/console.php` — `$schedule->command('etl:run')->dailyAt('02:00')`
- [ ] Commit: "ETL pipeline jobs"

### 9.4 DB 14 Research Migrations (ETL-only, air-gapped)

- [ ] `anonymized_harvests` — de-identified harvest records (county-level GPS, cohort_id not user_id, no PII)
- [ ] `anonymized_sightings` — de-identified wildlife sighting data
- [ ] `population_estimates` — derived wildlife population estimates per county per season
- [ ] `research` connection — only ETL job classes connect; no `app_user` credential exists for this DB
- [ ] `App\Jobs\Research\EtlAnonymizeHarvests` — strips PII, generalizes GPS to county centroid, writes to `research`
- [ ] Verify: no controller, model, or service references the `research` connection
- [ ] Commit: "DB 14 Research migrations and anonymization ETL"

### 9.5 Reporting Suite (`/reports`)

- [ ] Separate Inertia layout for the reporting portal; accessible to landowners and admins
- [ ] `GET /reports` — dashboard home; active metrics cards (MRR, active leases, harvest count)
- [ ] `GET /reports/financial` — financial dashboard; gross revenue, platform fees, payouts, YTD; reads from DB 8 `platform_daily_metrics`
- [ ] `GET /reports/leases` — lease occupancy, renewal rates, expiring leases queue
- [ ] `GET /reports/harvest` — harvest summary by species, season, property; quota utilization
- [ ] `GET /reports/wildlife` — population trend charts from sighting + harvest data
- [ ] `GET /reports/properties` — per-property performance comparison
- [ ] Export endpoints: `GET /reports/{type}/export?format=pdf|csv` — dispatches `App\Jobs\Analytics\GenerateExportReport`
- [ ] Scheduled email digest: `App\Jobs\Analytics\SendLandownerMonthlyReport` — dispatched monthly to landowners with active leases
- [ ] `GET /reports/1099` — landowner 1099 document access; links to stored PDFs in DB 11
- [ ] Reporting portal reads exclusively from DB 8 (`analytics` connection — `ReadOnlyModel`)
- [ ] Commit: "Reporting suite"

### 9.6 Admin Analytics (Filament)

- [ ] Admin home dashboard — Filament widgets showing key metrics from DB 8: MRR, active users, active leases, open SOS events, pending applications
- [ ] `AuditLogResource` — Filament read-only viewer for DB 9 audit log; filterable by event type, entity, actor, date range; no edit or delete actions visible
- [ ] Promotion performance panel — claims vs. limits, conversion to paid, per-promo metrics
- [ ] Commit: "Filament analytics and audit log viewer"

### Phase 9 Milestone

- [ ] `php artisan etl:run` populates all DB 8 tables with correct aggregations from production data
- [ ] DB 9 immutability verified: UPDATE and DELETE commands are no-ops at both app and DB level
- [ ] DB 14 ETL anonymizes harvest data correctly (no PII, county-level GPS)
- [ ] Reporting suite at `/reports` shows correct financial and harvest metrics for a landowner
- [ ] PDF and CSV exports generate and download correctly
- [ ] Admin audit log viewer shows all recorded events, read-only
- [ ] **MILESTONE: Analytics pipeline running, reporting suite live, audit log verified immutable**

---

## PHASE 10 — Admin, Compliance & Launch Prep

The goal: the platform is production-ready — fully administered, security-reviewed, load-tested, integrated with all third-party compliance services, and launched.

### 10.1 Full Filament Admin Panel Completion

- [ ] All Filament Resources reviewed for completeness across all domains
- [ ] User management: trust score admin, suspension/ban workflow, impersonation (audit-logged, time-limited)
- [ ] OFAC/AML review queue — flagged users from `OfacService`; resolution workflow
- [ ] Veteran verification review — ID.me + DD-214 upload review; apply veteran tier on approval
- [ ] Landowner Succession resource — entity ownership management, succession designation
- [ ] IoT device management resource — smart lock provisioning, access log, health monitoring
- [ ] Carbon credit module — sequestration calculator display, broker connection, program eligibility
- [ ] Content management — FAQ, static pages, blog post editor (Filament content blocks)
- [ ] Maintenance mode toggle — per-portal with custom messaging
- [ ] Commit: "Full Filament admin panel"

### 10.2 Background Check & Identity Compliance (Checkr + ID.me)

- [ ] `App\Services\Identity\CheckrService` — submit background check, handle Checkr webhook, write result to DB 1 `identity_verifications`; webhook on `priority` queue
- [ ] Background check auto-triggers at outfitter and consultant signup (required) and hunter signup (optional, prompted)
- [ ] Background check result: clear → trust score increment; consider → manual review queue; suspended → block lease activation
- [ ] ID.me OAuth integration for veteran verification — `App\Services\Identity\IdMeService`; on success: `users.is_veteran = true`, veteran tier auto-applied, Discord bot notified for role assignment
- [ ] DD-214 fallback — upload + manual admin review workflow
- [ ] Commit: "Checkr background checks and ID.me veteran verification"

### 10.3 Tax Compliance Completion (TaxJar + Tax1099)

- [ ] TaxJar nexus tracking — economic nexus thresholds per state monitored; alert when approaching
- [ ] TaxJar remittance automation — monthly/quarterly auto-remit job
- [ ] Tax1099 year-end run — `php artisan tax:generate-1099` — processes all qualifying recipients, e-files via Tax1099 API, stores records in DB 4, sends recipient notification with PDF link
- [ ] Exemption certificate workflow — upload, store in DB 11, flag account as exempt in billing
- [ ] Commit: "Tax compliance automation"

### 10.4 Founding Landowner Promotion — Launch Configuration

- [ ] Verify `promotional_periods` row for `founding_landowner_2025` is `status = 'draft'` in staging
- [ ] Set `claim_limit = 500`, `duration_days = 365`, `grants_plan_id` = Ranch plan ID
- [ ] Verify `show_on_landing = true`, `show_claim_counter = true`
- [ ] Test: sign up as a new landowner, publish a verified listing → promo auto-applies → verify 90-day Honeymoon correctly yields to 12-month Founding Landowner (more generous wins)
- [ ] Verify Stripe coupon auto-created matching the promo
- [ ] Set `status = 'scheduled'` with `starts_at` = launch date in staging; flip to `active` at launch
- [ ] Commit: "Founding Landowner promotion configured and verified"

### 10.5 Production Environment — On-Prem Stage 1

- [ ] Provision Hetzner production server(s) per `deployment_strategy.md` specs
- [ ] Ubuntu 24.04 LTS hardened (SSH keys, UFW, fail2ban, unattended-upgrades)
- [ ] Docker Engine + Compose V2 installed
- [ ] Production `.env.prod` configured (all secrets, DB credentials, Stripe live keys, Mapbox token, etc.)
- [ ] `docker-compose.prod.yml` deployed (app, worker, scheduler, reverb, Garage, 14 PostgreSQL, 5 Valkey, Nginx)
- [ ] Nginx on host: SSL termination via Let's Encrypt / Certbot for `americanheadhunter.com`
- [ ] Cloudflare DNS + WAF + CDN in front of Nginx
- [ ] Garage production provisioned with all buckets created and IAM credentials set
- [ ] `php artisan migrate:all --fresh --seed` — runs clean on production (seeded with launch data, NOT test data)
- [ ] Commit: "Production environment provisioned"

### 10.6 CI/CD Pipeline (GitHub Actions)

- [ ] Azure Container Registry (Basic tier) provisioned — all images stored here from day one
- [ ] Production `Dockerfile` (multi-stage: build assets → PHP-FPM image)
- [ ] GitHub Actions workflow: `test → build → push to ACR → deploy`
  - Test: `composer install`, `php artisan test`, `npm run build`
  - Build: `docker build -t ahregistry.azurecr.io/american-headhunter/app:{sha}`
  - Push to ACR on `main` branch
  - Deploy: SSH to production, `docker compose pull && docker compose up -d`, `php artisan migrate:all`, restart workers
- [ ] `APP_VERSION` env var set to the git SHA on each deploy for traceability
- [ ] Rollback documented: redeploy previous image SHA from ACR
- [ ] Commit: "CI/CD pipeline"

### 10.7 Monitoring & Operations

- [ ] Sentry error tracking integrated (Laravel Sentry SDK + source maps for React)
- [ ] Prometheus + Grafana on production for Laravel queue depth, DB connection pool, Valkey memory
- [ ] Grafana Loki for log aggregation (Laravel JSON logs → Loki)
- [ ] Uptime monitoring — external check on `/health` endpoint → PagerDuty alert on failure
- [ ] Database backup strategy: nightly `pg_dump` → local NAS; weekly offsite to Backblaze B2
- [ ] Backup restore test: restore DB 1 from backup and verify user authentication works
- [ ] VMware/Hetzner snapshot schedule (weekly baseline snapshots)
- [ ] Status page (public, hosted separately) — manual incident posting initially
- [ ] Commit: "Monitoring and operations setup"

### 10.8 Security Review & Load Testing

- [ ] Security review: OWASP Top 10 self-assessment against codebase
- [ ] Penetration test: schedule third-party pentest (HackerOne or equivalent)
- [ ] Load testing: target 500 concurrent users browsing + 50 concurrent auction bids; verify Valkey auction cluster handles real-time bid state without race conditions
- [ ] Verify: no encrypted field values appear in any application log (grep all log output for field names flagged in schema docs)
- [ ] Verify: no raw card numbers or Stripe secrets in logs
- [ ] Verify: all audit log entries are immutable (spot-check UPDATE/DELETE attempts)
- [ ] axe-core accessibility run on all public-facing pages; resolve all critical and serious violations
- [ ] Legal review: terms of service, privacy policy, lease template attorney review complete
- [ ] CCPA compliance review — data inventory map, right-to-deletion tested end-to-end
- [ ] Commit: "Security review and load test results documented"

### 10.9 Launch — Soft Launch → Public

- [ ] Flip `founding_landowner_2025` promotional period to `status = 'active'`
- [ ] Soft launch: invite first cohort (target 20–30 landowners + 50 hunters)
- [ ] Monitor for 72 hours: error rates, queue depth, DB query times, SOS system test
- [ ] Critical bug SLA: any P1 (data loss, payment error, SOS failure) — fix and redeploy within 4 hours
- [ ] Collect NPS from first cohort at day 7
- [ ] Public launch: remove invite gate, open registration to all
- [ ] Publish Discord server, send launch announcement
- [ ] Monitor Founding Landowner claim counter — verify scarcity signal displaying on landing page
- [ ] **MILESTONE: American Headhunter is live**

---

## Current Position

Phases 1, 2, 3, 3.9, 3.10, 4.1–4.5 complete as of 2026-06-10. The lease lifecycle is functional end-to-end: hunters apply, admins approve, in-platform e-signature activates the lease. Admin panel covers full platform user management (CustomerUserResource) and full lease application review (LeaseApplicationResource).

**Immediate next actions:**

1. **Phase 4.5.5** — Custom Lease Contracts (Dropbox Sign, Ranch+ tier): migrations → `DropboxSignService` → webhook controller + job → `EsignatureService` modification → approval-time upload UI → `dropboxsign:simulate` Artisan command
2. **Phase 4.6** — Member portal lease dashboard (`/member`): active lease view, gate code decrypt, stand map, QR check-in page, `CheckInService`
3. **Phase 4.7** — Document generation jobs: `GenerateLeasePdf`, `GenerateQrCode`, `ScanUploadedFile`

**Open items:**
- SEC-024: Configure `TrustProxies` middleware before relying on IP allowlist in production
- SEC-025: Audit role changes on admin user save (pivot table sync not currently logged)
- Phase 4.9 profile templates: Outfitter, Landowner, Consultant, Seller, Advertiser, Corporate (Hunter template partially built)

---

## Notes on Execution

- **Commit after every sub-section.** Small commits make debugging and rollback tractable.
- **Don't skip the milestones.** Each milestone is a proof point before you build more on top.
- **Build vertical slices.** One complete feature working end-to-end teaches more than all the models with no UI.
- **Test immutability rules explicitly.** At each phase that touches DB 9 or DB 8, manually verify that write attempts fail.
- **Never hardcode prices, tier names, or feature limits.** Every entitlement check goes through `EntitlementService`.
- **Defer paid API integrations** (Stripe live, Checkr, ID.me, Tax1099) until the specific feature that needs them is being built — use test mode throughout Phases 2–9.
- **Use Claude Code for all phases.** `CLAUDE.md` is the guide — load the relevant doc files before writing code for each domain.
