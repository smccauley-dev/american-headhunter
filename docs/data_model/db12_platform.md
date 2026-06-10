# DB 12 — Platform Configuration

**Server:** Standard PostgreSQL — heavily Valkey-cached
**Encryption Key:** Key L — rotated annually
**Laravel Connection:** `platform`
**Database:** `ah_platform`
**DB User:** `ah_app`
**Access:** All application services (read via Valkey cache), admin writes (super_admin, platform_admin roles only)

---

## Implementation Note (Phase 3.1 — 2026-05-24)

The migrations in `database/migrations/platform/` were built using **`docs/pricing_schema_additions.md`** as the canonical schema source for the pricing tables. The schemas below reflect the original design; the key differences in what was actually built are:

- `membership_plans` — richer schema: `plan_key` (not `code`), `monthly_price_cents`, `annual_price_cents`, `platform_fee_pct`, `commission_pct`, `stripe_*` IDs, `is_default_free`, `deprecated_at`, soft deletes
- `plan_versions` — uses `superseded_at TIMESTAMPTZ` (not `is_current BOOLEAN`); has `entitlements_snapshot JSONB` for fast offline resolution; `change_reason` and `created_by_user_id` fields; immutability enforced by PostgreSQL RULE (`plan_versions_no_update`)
- `feature_entitlements` — links to `membership_plans` (not `plan_versions`); has `feature_type` enum (`boolean`, `integer`, `string`, `json`) with separate `bool_value`, `int_value`, `string_value`, `json_value` columns; `display_label`, `display_description`, `show_on_pricing` for pricing page rendering
- `promotional_periods` — uses PostgreSQL enum types (`promotion_type`, `promotion_status`); richer targeting (`target_states TEXT[]`, `target_rules_json JSONB`); display controls (`show_on_landing`, `show_claim_counter`, etc.)
- `notification_templates` + `notification_template_versions` added — email/push/SMS template management with draft → review → production → archived promotion workflow (replaces `EmailTemplate` placeholder)
- `promo_claims` — lives in **DB 4 Billing** as `promotion_claims` (not DB 12); will be added in Phase 4

The Eloquent models section below reflects the actual implementation.

---

## CRITICAL RULES

- **Never hardcode subscription prices, tier names, or feature limits in application code.** All pricing and tier definitions live in `membership_plans`, `plan_versions`, and `feature_entitlements`.
- **Never hardcode promotion rules.** Launch promos, seasonal discounts, honeymoon periods, referral programs, and promo codes all live in `promotional_periods`.
- **Always check feature access via `EntitlementService`.** Never compare `user->plan_name` or `subscription->tier` directly in application logic. Use `$entitlements->can($user, 'feature_key')`.
- **Plan versions are immutable.** Changing a plan's pricing or entitlements creates a new `plan_versions` row. Existing subscribers keep their grandfathered version unless an admin explicitly migrates them.
- **Entitlement cache (Valkey Cluster 2) must be invalidated** on: subscription change, promo claim activation/expiration, plan version update, feature flag toggle.

---

## Purpose

All platform-wide and tenant-specific configuration. Virtually every table here is cached in Valkey with a long TTL — application services read from cache; admin writes invalidate and repopulate. Low write frequency, very high read frequency. Drives: feature flags, subscription tiers, pricing, promotions, IoT device registry, and advertising campaigns.

---

## Valkey Cache Strategy

```
Key pattern (feature flags):     platform:flags:<key>
Key pattern (plan entitlements): platform:entitlements:<plan_version_id>
Key pattern (user entitlements): platform:user_entitlements:<user_id>
Key pattern (tenant settings):   platform:tenant:<key>
TTL (feature_flags):             5 minutes
TTL (plan_versions/entitlements): 60 minutes
TTL (user_entitlements):         10 minutes
TTL (tenant_settings):           30 minutes
Invalidation:                    Tag-based, triggered by TenantService / EntitlementService on write
```

---

## Extensions Required

```sql
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

---

## Shared Trigger

```sql
CREATE OR REPLACE FUNCTION trigger_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
```

---

## Tables

### feature_flags
Controls which features are active platform-wide, for specific roles, for specific users, or for a percentage rollout.

```sql
CREATE TABLE feature_flags (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    key                     VARCHAR(100) NOT NULL,
    display_name            VARCHAR(200) NOT NULL,
    description             TEXT,
    is_enabled              BOOLEAN NOT NULL DEFAULT false,
    enabled_for_roles       JSONB NOT NULL DEFAULT '[]',       -- Array of role names that always see this as enabled
    enabled_for_user_ids    JSONB NOT NULL DEFAULT '[]',       -- Array of user UUIDs for targeted enablement
    rollout_percentage      SMALLINT NOT NULL DEFAULT 0,       -- 0-100; percent of users who see this enabled
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_feature_flags_key UNIQUE (key),
    CONSTRAINT chk_feature_flags_rollout
        CHECK (rollout_percentage >= 0 AND rollout_percentage <= 100)
);

CREATE INDEX idx_feature_flags_key ON feature_flags (key);
CREATE INDEX idx_feature_flags_enabled ON feature_flags (is_enabled);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON feature_flags
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**Seed data — all platform feature flags:**

```sql
INSERT INTO feature_flags (key, display_name, is_enabled) VALUES
    ('auction_module',              'Live Lease Auctions',                  false),
    ('consulting_marketplace',      'Wildlife Consulting Marketplace',       false),
    ('outfitter_booking',           'Outfitter & Guide Bookings',            false),
    ('equipment_marketplace',       'Equipment & Gear Marketplace',          false),
    ('club_leases',                 'Hunting Club Lease Support',            true),
    ('carbon_credits',              'Carbon Credit Leasing',                 false),
    ('smart_lock_iot',              'Smart Lock & IoT Integrations',         false),
    ('bundled_insurance',           'Bundled Lease Insurance',               false),
    ('ai_trophy_scoring',           'AI Trophy Score Estimation',            false),
    ('public_api',                  'Public Developer API',                  false),
    ('data_monetization',           'Research Data Licensing',               false),
    ('digital_id_cards',            'Digital Hunter ID Cards',               false),
    ('veteran_discounts',           'Veteran Discount Program',              true),
    ('youth_programs',              'Youth Hunter Programs',                 true),
    ('offline_pwa',                 'Offline Progressive Web App',           false),
    ('saml_sso',                    'Enterprise SAML SSO',                   false),
    ('two_person_authorization',    'Dual-Approval Admin Actions',           false),
    ('lease_wanted_board',          'Lease Wanted Board',                    false),
    ('population_modeling',         'Wildlife Population Modeling',          false),
    ('wildlife_photography_tourism','Wildlife Photography Tourism',          false),
    ('club_expense_sharing',        'Club Expense Sharing & Splitting',      false);
```

---

### membership_plans
The available subscription tiers. One row per plan concept. Pricing lives in `plan_versions` to support versioning.

```sql
CREATE TABLE membership_plans (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    code            VARCHAR(50) NOT NULL,
    display_name    VARCHAR(200) NOT NULL,
    description     TEXT,
    account_type    VARCHAR(20) NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    is_public       BOOLEAN NOT NULL DEFAULT true,   -- false = internal/grandfathered plan
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_membership_plans_code UNIQUE (code),
    CONSTRAINT chk_membership_plans_account_type
        CHECK (account_type IN ('hunter', 'landowner', 'club', 'outfitter', 'consultant'))
);

CREATE INDEX idx_membership_plans_code ON membership_plans (code);
CREATE INDEX idx_membership_plans_account_type ON membership_plans (account_type)
    WHERE is_active = true;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON membership_plans
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**Seed data:**

```sql
INSERT INTO membership_plans (code, display_name, account_type, sort_order) VALUES
    ('hunter_free',       'Hunter — Free',           'hunter',    10),
    ('hunter_pro',        'Hunter Pro',               'hunter',    20),
    ('landowner_basic',   'Landowner Basic',          'landowner', 10),
    ('landowner_pro',     'Landowner Pro',            'landowner', 20),
    ('landowner_elite',   'Landowner Elite',          'landowner', 30),
    ('club_basic',        'Club Basic',               'club',      10),
    ('club_pro',          'Club Pro',                 'club',      20),
    ('outfitter_standard','Outfitter Standard',       'outfitter', 10),
    ('consultant_basic',  'Consultant Basic',         'consultant',10);
```

---

### plan_versions
Immutable pricing snapshots. Changing a plan's price creates a new row — never update an existing version. Existing subscribers remain on their version (`subscription.plan_version_id` in DB 4).

```sql
CREATE TABLE plan_versions (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    plan_id                 UUID NOT NULL REFERENCES membership_plans (id),
    version_number          SMALLINT NOT NULL,
    price_monthly_cents     BIGINT,                  -- NULL for free plans
    price_annual_cents      BIGINT,                  -- NULL for free plans
    stripe_price_id_monthly VARCHAR(100),            -- Stripe Price ID for monthly billing
    stripe_price_id_annual  VARCHAR(100),            -- Stripe Price ID for annual billing
    is_current              BOOLEAN NOT NULL DEFAULT false,
    effective_from          DATE NOT NULL,
    effective_until         DATE,                    -- NULL = still current
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — plan versions are immutable once created

    CONSTRAINT uq_plan_versions_plan_version UNIQUE (plan_id, version_number)
);

CREATE INDEX idx_plan_versions_plan_id ON plan_versions (plan_id);
CREATE INDEX idx_plan_versions_current ON plan_versions (plan_id, is_current)
    WHERE is_current = true;
```

**NOTE:** When updating a plan's price, first set `is_current = false` on the existing version, then insert a new version with `is_current = true`. Use a database transaction. The application never updates `plan_versions` rows — only the admin migration command does.

---

### feature_entitlements
Defines what each plan version can do. Checked at runtime via `EntitlementService` — never hardcode limits in application code.

```sql
CREATE TABLE feature_entitlements (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    plan_version_id UUID NOT NULL REFERENCES plan_versions (id),
    feature_key     VARCHAR(100) NOT NULL,
    limit_value     INT,                    -- NULL = unlimited; 0 = disabled via limit; use is_enabled for explicit disable
    is_enabled      BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — entitlements are immutable; new plan version = new entitlement rows

    CONSTRAINT uq_feature_entitlements_version_key UNIQUE (plan_version_id, feature_key)
);

CREATE INDEX idx_feature_entitlements_plan_version_id ON feature_entitlements (plan_version_id);
CREATE INDEX idx_feature_entitlements_key ON feature_entitlements (feature_key);
```

**Standard feature keys and their semantics:**

| Feature Key | Type | Description |
|---|---|---|
| `max_active_listings` | limit (INT) | Max simultaneously active property listings |
| `max_photos_per_listing` | limit (INT) | Max photos per listing |
| `max_lease_hunters` | limit (INT) | Max hunters on a single lease |
| `harvest_log_access` | boolean | Can log harvests |
| `trail_camera_access` | boolean | Can manage trail cameras |
| `analytics_dashboard` | boolean | Access to the Reporting Suite |
| `priority_support` | boolean | Priority support queue |
| `auction_access` | boolean | Can participate in lease auctions |
| `custom_lease_templates` | boolean | Can upload custom lease templates |
| `api_access` | boolean | Can generate API keys |
| `club_management` | boolean | Can create and manage a hunting club |
| `document_storage_gb` | limit (INT) | Document storage quota in GB |

---

### promotional_periods
Admin-configured promotions. Covers founding member discounts, honeymoon free periods, seasonal discounts, referral programs, veteran discounts, and promo codes. Never hardcode promo logic in application code.

```sql
CREATE TABLE promotional_periods (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    code                    VARCHAR(50) NOT NULL,
    promo_type              VARCHAR(20) NOT NULL,
    display_name            VARCHAR(200) NOT NULL,
    description             TEXT,
    discount_type           VARCHAR(20) NOT NULL,
    discount_value          BIGINT,                  -- Percent (0-100) or fixed cents, depending on discount_type
    free_period_days        SMALLINT,                -- Populated for discount_type = 'free_period'
    applies_to_plan_ids     JSONB NOT NULL DEFAULT '[]',    -- Array of membership_plans.id UUIDs (empty = all plans)
    max_redemptions         INT,                     -- NULL = unlimited
    redemption_count        INT NOT NULL DEFAULT 0,
    requires_promo_code     BOOLEAN NOT NULL DEFAULT false,
    promo_code              VARCHAR(50),             -- The code a user enters (if requires_promo_code = true)
    eligible_account_types  JSONB NOT NULL DEFAULT '[]',    -- Empty = all account types eligible
    starts_at               TIMESTAMPTZ,
    ends_at                 TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT true,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_promotional_periods_code UNIQUE (code),
    CONSTRAINT chk_promotional_periods_type
        CHECK (promo_type IN (
            'founding_member', 'honeymoon', 'seasonal', 'referral', 'veteran', 'promo_code'
        )),
    CONSTRAINT chk_promotional_periods_discount_type
        CHECK (discount_type IN ('percent', 'fixed_cents', 'free_period')),
    CONSTRAINT chk_promotional_periods_promo_code
        CHECK (
            (requires_promo_code = false) OR
            (requires_promo_code = true AND promo_code IS NOT NULL)
        )
);

CREATE INDEX idx_promotional_periods_code ON promotional_periods (code);
CREATE INDEX idx_promotional_periods_active ON promotional_periods (is_active, starts_at, ends_at)
    WHERE is_active = true;
CREATE INDEX idx_promotional_periods_promo_code ON promotional_periods (promo_code)
    WHERE promo_code IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON promotional_periods
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### promo_claims
Records which users have claimed which promotions. Prevents double-claiming and tracks free period end dates.

```sql
CREATE TABLE promo_claims (
    id                          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                     UUID NOT NULL,           -- References DB 1 (Identity) users.id
    promotion_id                UUID NOT NULL REFERENCES promotional_periods (id),
    claimed_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    applied_to_subscription_id  UUID,                    -- References DB 4 (Billing) subscriptions.id
    free_period_ends_at         DATE,                    -- Populated for free_period discount type
    discount_applied_cents      BIGINT,                  -- Actual discount amount applied
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — claim records are immutable

    CONSTRAINT uq_promo_claims_user_promotion UNIQUE (user_id, promotion_id)
);

CREATE INDEX idx_promo_claims_user_id ON promo_claims (user_id);
CREATE INDEX idx_promo_claims_promotion_id ON promo_claims (promotion_id);
CREATE INDEX idx_promo_claims_free_period ON promo_claims (free_period_ends_at)
    WHERE free_period_ends_at IS NOT NULL;
```

---

### tenant_settings
Platform-wide and per-tenant (future multi-tenant) configuration key-value store. Cached in Valkey.

```sql
CREATE TABLE tenant_settings (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    key         VARCHAR(100) NOT NULL,
    value       JSONB NOT NULL,
    description TEXT,
    is_public   BOOLEAN NOT NULL DEFAULT false,     -- true = safe to expose to frontend
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_tenant_settings_key UNIQUE (key)
);

CREATE INDEX idx_tenant_settings_key ON tenant_settings (key);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON tenant_settings
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### iot_devices
Smart locks, cellular trail cameras, and weather stations registered on properties. Requires `smart_lock_iot` feature flag.

```sql
CREATE TABLE iot_devices (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id         UUID NOT NULL,               -- References DB 2 (Property) properties.id
    device_type         VARCHAR(30) NOT NULL,
    provider            VARCHAR(50) NOT NULL,         -- e.g. 'lockly', 'august', 'reconyx', 'spypoint'
    provider_device_id  VARCHAR(255) NOT NULL,
    name                VARCHAR(100) NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'unknown',
    last_seen_at        TIMESTAMPTZ,
    config              JSONB NOT NULL DEFAULT '{}',  -- Device-specific config (encrypted if contains credentials)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ,

    CONSTRAINT chk_iot_devices_type
        CHECK (device_type IN ('smart_lock', 'trail_camera_cellular', 'weather_station')),
    CONSTRAINT chk_iot_devices_status
        CHECK (status IN ('online', 'offline', 'unknown'))
);

CREATE INDEX idx_iot_devices_property_id ON iot_devices (property_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_iot_devices_provider ON iot_devices (provider, provider_device_id);
CREATE INDEX idx_iot_devices_status ON iot_devices (status)
    WHERE deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON iot_devices
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### ad_campaigns
Sponsored listing campaigns created by advertisers (landowners, outfitters). Ad performance metrics are tracked in DB 8 `ad_campaign_metrics`.

```sql
CREATE TABLE ad_campaigns (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    advertiser_user_id  UUID NOT NULL,               -- References DB 1 (Identity) users.id
    campaign_name       VARCHAR(200) NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    budget_cents        BIGINT NOT NULL,
    spent_cents         BIGINT NOT NULL DEFAULT 0,
    impression_count    INT NOT NULL DEFAULT 0,
    click_count         INT NOT NULL DEFAULT 0,
    starts_at           DATE NOT NULL,
    ends_at             DATE NOT NULL,
    target_states       JSONB NOT NULL DEFAULT '[]',     -- Array of state codes (empty = national)
    target_species      JSONB NOT NULL DEFAULT '[]',     -- Array of species codes (empty = all species)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ,

    CONSTRAINT chk_ad_campaigns_status
        CHECK (status IN ('draft', 'active', 'paused', 'completed', 'cancelled')),
    CONSTRAINT chk_ad_campaigns_dates
        CHECK (ends_at >= starts_at),
    CONSTRAINT chk_ad_campaigns_budget
        CHECK (budget_cents > 0)
);

CREATE INDEX idx_ad_campaigns_advertiser ON ad_campaigns (advertiser_user_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_ad_campaigns_status ON ad_campaigns (status, starts_at, ends_at)
    WHERE deleted_at IS NULL AND status = 'active';
CREATE INDEX idx_ad_campaigns_dates ON ad_campaigns (starts_at, ends_at)
    WHERE deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON ad_campaigns
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

## Eloquent Models

```php
namespace App\Models\Platform;

class FeatureFlag extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'feature_flags';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_enabled'           => 'boolean',
            'enabled_for_roles'    => 'array',
            'enabled_for_user_ids' => 'array',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Platform;

class MembershipPlan extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'membership_plans';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'is_public'  => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }

    public function currentVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PlanVersion::class, 'plan_id')
            ->where('is_current', true);
    }
}
```

```php
namespace App\Models\Platform;

class PlanVersion extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'plan_versions';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_current'     => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'created_at'     => 'datetime',
        ];
    }

    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FeatureEntitlement::class, 'plan_version_id');
    }
}
```

```php
namespace App\Models\Platform;

class FeatureEntitlement extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'feature_entitlements';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_enabled'  => 'boolean',
            'created_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Platform;

class PromotionalPeriod extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'promotional_periods';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'applies_to_plan_ids'    => 'array',
            'eligible_account_types' => 'array',
            'requires_promo_code'    => 'boolean',
            'is_active'              => 'boolean',
            'starts_at'              => 'datetime',
            'ends_at'                => 'datetime',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime',
        ];
    }
}
```

```php
// NOTE: PromoClaim lives in DB 4 Billing as App\Models\Billing\PromotionClaim (added in Phase 4)
// There is no promo_claims table in DB 12.
```

```php
namespace App\Models\Platform;

class NotificationTemplate extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'notification_templates';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'variable_schema' => 'array',
            'is_active'       => 'boolean',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id');
    }

    public function productionVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NotificationTemplateVersion::class, 'template_id')
            ->where('status', 'production');
    }
}
```

```php
namespace App\Models\Platform;

class NotificationTemplateVersion extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'platform';
    protected $table      = 'notification_template_versions';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'promoted_at' => 'datetime',
            'archived_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Platform;

class IotDevice extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'platform';
    protected $table      = 'iot_devices';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'config'       => 'array',
            'last_seen_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Platform;

class AdCampaign extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'platform';
    protected $table      = 'ad_campaigns';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'target_states'  => 'array',
            'target_species' => 'array',
            'starts_at'      => 'date',
            'ends_at'        => 'date',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }
}
```

---

## EntitlementService

The canonical way to check feature access. Never bypass this with direct plan comparisons.

```php
namespace App\Services\Platform;

class EntitlementService
{
    /**
     * Check whether a user can use a feature.
     *
     * @param  \App\Models\Identity\User  $user
     * @param  string                     $featureKey  e.g. 'auction_access', 'max_active_listings'
     * @return bool
     */
    public function can(\App\Models\Identity\User $user, string $featureKey): bool
    {
        $entitlements = $this->getUserEntitlements($user);
        return $entitlements[$featureKey]['is_enabled'] ?? false;
    }

    /**
     * Get the numeric limit for a feature (NULL = unlimited).
     */
    public function limit(\App\Models\Identity\User $user, string $featureKey): ?int
    {
        $entitlements = $this->getUserEntitlements($user);
        return $entitlements[$featureKey]['limit_value'] ?? null;
    }

    /**
     * Load and cache the full entitlement set for a user.
     * Cache key: platform:user_entitlements:<user_id>
     * TTL: 10 minutes — invalidate on subscription change or promo claim.
     */
    private function getUserEntitlements(\App\Models\Identity\User $user): array
    {
        return \Cache::store('valkey')->remember(
            "platform:user_entitlements:{$user->id}",
            now()->addMinutes(10),
            function () use ($user) {
                // 1. Look up the user's active subscription in DB 4 (via BillingService)
                // 2. Get the plan_version_id from the subscription
                // 3. Load feature_entitlements for that plan_version_id
                // 4. Overlay any active promo claims that modify entitlements
                return $this->buildEntitlements($user);
            }
        );
    }
}
```

Usage in application code:

```php
// CORRECT
$entitlements = app(EntitlementService::class);

if ($entitlements->can($user, 'auction_access')) {
    // show auction UI
}

$maxListings = $entitlements->limit($user, 'max_active_listings'); // returns INT or null (unlimited)

// WRONG — never do this
if ($user->subscription->plan_name === 'landowner_pro') { ... }
if ($user->plan_tier >= 2) { ... }
```

---

## Feature Flag Helper

```php
// Blade
@if(feature('auction_module'))
    <x-auction-widget />
@endif

// PHP
if (feature('consulting_marketplace')) {
    // show consulting UI
}

// The feature() helper is defined in AppServiceProvider:
// function feature(string $key): bool {
//     return app(FeatureFlagService::class)->isEnabled($key);
// }
```

---

## Common Pitfalls

- **Never update a `plan_versions` row.** Create a new version. Updating an existing version breaks grandfathered subscribers.
- **Never update a `feature_entitlements` row.** Create new entitlement rows on a new plan version.
- **Always invalidate Valkey when writing to this database.** `FeatureFlagService`, `EntitlementService`, and `TenantService` handle invalidation — do not write directly to these tables outside those services.
- **`promo_claims.applied_to_subscription_id` is a cross-DB reference to DB 4.** Do not add a foreign key constraint — it is a bare UUID column.
- **`iot_devices.config` may contain API credentials.** Treat it as encrypted when it does — never log the raw `config` JSONB for IoT devices.
