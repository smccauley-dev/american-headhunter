# Database Schema Additions — Pricing & Promotions

New tables required to support admin-configurable pricing, feature entitlements, and promotional periods. These additions go into **DB 12 Platform** and **DB 4 Billing**.

---

## DB 12 Platform — New Tables

### `membership_plans`

Stores every subscription tier definition. Admin-editable via Filament.

```sql
CREATE TABLE membership_plans (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_key                VARCHAR(50) UNIQUE NOT NULL,    -- 'sportsman_hunter', 'ranch_landowner'
    account_type            VARCHAR(30) NOT NULL,           -- References users.account_type enum
    display_name            VARCHAR(100) NOT NULL,
    description             TEXT,
    tagline                 VARCHAR(255),

    -- Pricing (configurable)
    monthly_price_cents     INTEGER NOT NULL DEFAULT 0,
    annual_price_cents      INTEGER NOT NULL DEFAULT 0,
    currency                CHAR(3) NOT NULL DEFAULT 'USD',

    -- Platform fee overrides (for landowner tiers)
    platform_fee_pct        DECIMAL(5,2),                   -- e.g., 5.00 for Homestead, 3.00 for Ranch
    commission_pct          DECIMAL(5,2),                   -- For outfitters, consultants, sellers

    -- Billing options
    monthly_enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    annual_enabled          BOOLEAN NOT NULL DEFAULT TRUE,

    -- Stripe integration
    stripe_product_id       VARCHAR(100),
    stripe_monthly_price_id VARCHAR(100),
    stripe_annual_price_id  VARCHAR(100),

    -- Display controls
    sort_order              INTEGER NOT NULL DEFAULT 0,
    is_public               BOOLEAN NOT NULL DEFAULT TRUE,   -- Shows on pricing page
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,   -- Can new users subscribe
    is_default_free         BOOLEAN NOT NULL DEFAULT FALSE,  -- Fallback tier after promo expiration

    -- Metadata
    admin_notes             TEXT,
    launched_at             TIMESTAMP WITH TIME ZONE,
    deprecated_at           TIMESTAMP WITH TIME ZONE,        -- Soft-end for new signups

    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_membership_plans_account_type ON membership_plans(account_type) WHERE deleted_at IS NULL;
CREATE INDEX idx_membership_plans_active ON membership_plans(is_active, is_public) WHERE deleted_at IS NULL;
CREATE INDEX idx_membership_plans_sort ON membership_plans(account_type, sort_order) WHERE deleted_at IS NULL;

COMMENT ON TABLE membership_plans IS 'Admin-configurable subscription tier definitions — never hardcoded in app code';
```

### `plan_versions`

Immutable snapshots of plan terms at the time each subscription was created. Supports grandfathered pricing.

```sql
CREATE TABLE plan_versions (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_id                 UUID NOT NULL REFERENCES membership_plans(id),
    version_number          INTEGER NOT NULL,

    -- Immutable snapshot of plan at this version
    plan_key                VARCHAR(50) NOT NULL,
    display_name            VARCHAR(100) NOT NULL,
    monthly_price_cents     INTEGER NOT NULL,
    annual_price_cents      INTEGER NOT NULL,
    platform_fee_pct        DECIMAL(5,2),
    commission_pct          DECIMAL(5,2),
    entitlements_snapshot   JSONB NOT NULL,                  -- Feature entitlements at this version

    -- When this version was active
    effective_from          TIMESTAMP WITH TIME ZONE NOT NULL,
    superseded_at           TIMESTAMP WITH TIME ZONE,        -- When replaced by next version

    change_reason           TEXT,                             -- Why this version was created
    created_by_user_id      UUID,                             -- Admin who created it (DB 1 ref)

    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),

    UNIQUE (plan_id, version_number)
);

CREATE INDEX idx_plan_versions_plan ON plan_versions(plan_id, version_number DESC);
CREATE INDEX idx_plan_versions_effective ON plan_versions(plan_id, effective_from DESC);

COMMENT ON TABLE plan_versions IS 'Immutable plan snapshots — subscriptions reference specific versions to preserve grandfathered pricing';

-- Rule: plan_versions are never updated after creation
CREATE RULE plan_versions_no_update AS ON UPDATE TO plan_versions DO INSTEAD NOTHING;
```

### `feature_entitlements`

Per-plan feature flags and numeric limits. What each tier can do.

```sql
CREATE TABLE feature_entitlements (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_id             UUID NOT NULL REFERENCES membership_plans(id),
    feature_key         VARCHAR(100) NOT NULL,               -- 'trail_camera_integration', 'max_listings'

    -- Entitlement value (one of these is set based on feature_type)
    feature_type        VARCHAR(20) NOT NULL CHECK (feature_type IN ('boolean', 'integer', 'string', 'json')),
    bool_value          BOOLEAN,
    int_value           INTEGER,                              -- -1 means "unlimited"
    string_value        VARCHAR(255),
    json_value          JSONB,

    display_label       VARCHAR(255),                         -- Human-readable label for pricing page
    display_description TEXT,                                 -- Longer explanation
    display_order       INTEGER NOT NULL DEFAULT 0,
    show_on_pricing     BOOLEAN NOT NULL DEFAULT TRUE,

    created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),

    UNIQUE (plan_id, feature_key)
);

CREATE INDEX idx_entitlements_plan ON feature_entitlements(plan_id);
CREATE INDEX idx_entitlements_feature ON feature_entitlements(feature_key);

COMMENT ON TABLE feature_entitlements IS 'Per-plan feature access rules — queried by EntitlementService with Valkey caching';
```

### `promotional_periods`

Configurable promotions — launch deals, honeymoons, seasonal campaigns.

```sql
CREATE TYPE promotion_type AS ENUM (
    'tier_grant',              -- Free access to a tier for N days
    'percentage_discount',     -- % off subscription
    'dollar_discount',         -- $ off subscription
    'free_period',             -- Delay first charge
    'referral_program',        -- Credit per referral
    'promo_code_campaign'      -- Code-based activation
);

CREATE TYPE promotion_status AS ENUM (
    'draft',
    'scheduled',
    'active',
    'paused',
    'exhausted',               -- Claim limit hit
    'expired',                 -- End date passed
    'ended'                    -- Manually ended
);

CREATE TABLE promotional_periods (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    promo_key               VARCHAR(80) UNIQUE NOT NULL,     -- 'founding_landowner_2025'
    display_name            VARCHAR(200) NOT NULL,
    description             TEXT,

    promotion_type          promotion_type NOT NULL,
    status                  promotion_status NOT NULL DEFAULT 'draft',

    -- Target audience
    target_account_types    TEXT[] NOT NULL,                 -- ['landowner'], ['hunter', 'outfitter']
    target_states           TEXT[],                          -- Optional state restriction
    target_rules_json       JSONB,                           -- Advanced eligibility rules

    -- Benefit (meaning varies by promotion_type)
    grants_plan_id          UUID REFERENCES membership_plans(id),  -- For tier_grant
    duration_days           INTEGER,                          -- Length of free period
    discount_percentage     DECIMAL(5,2),                    -- For percentage_discount
    discount_amount_cents   INTEGER,                         -- For dollar_discount
    referral_reward_type    VARCHAR(30),                     -- 'credit', 'free_months', 'tier_grant'
    referral_reward_value   INTEGER,

    -- Post-expiration behavior
    on_expiration           VARCHAR(30) NOT NULL DEFAULT 'downgrade_free',
                                                              -- 'auto_charge' | 'downgrade_free' | 'pause_account'

    -- Time & claim limits
    starts_at               TIMESTAMP WITH TIME ZONE,        -- NULL = starts immediately on activation
    ends_at                 TIMESTAMP WITH TIME ZONE,        -- NULL = no end date
    claim_limit             INTEGER,                          -- NULL = unlimited
    claim_count             INTEGER NOT NULL DEFAULT 0,
    per_user_limit          INTEGER NOT NULL DEFAULT 1,

    -- Stacking & triggers
    stackable_with_other_promos BOOLEAN NOT NULL DEFAULT FALSE,
    stackable_with_veteran      BOOLEAN NOT NULL DEFAULT TRUE,
    requires_promo_code         BOOLEAN NOT NULL DEFAULT FALSE,
    auto_apply_on_signup        BOOLEAN NOT NULL DEFAULT FALSE,
    auto_apply_on_first_listing BOOLEAN NOT NULL DEFAULT FALSE,

    -- Display
    show_on_landing         BOOLEAN NOT NULL DEFAULT FALSE,
    show_on_pricing         BOOLEAN NOT NULL DEFAULT FALSE,
    show_claim_counter      BOOLEAN NOT NULL DEFAULT FALSE,
    landing_banner_text     TEXT,
    pricing_badge_text      VARCHAR(100),
    dashboard_callout_text  TEXT,

    -- Audit
    created_by_user_id      UUID,                            -- DB 1 ref
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    paused_at               TIMESTAMP WITH TIME ZONE,
    ended_at                TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_promo_periods_status ON promotional_periods(status) WHERE status IN ('active', 'scheduled');
CREATE INDEX idx_promo_periods_dates ON promotional_periods(starts_at, ends_at) WHERE status = 'active';
CREATE INDEX idx_promo_periods_auto_apply ON promotional_periods(auto_apply_on_signup, auto_apply_on_first_listing) WHERE status = 'active';

COMMENT ON TABLE promotional_periods IS 'Admin-configurable promotions — Founding Landowner, Honeymoon, seasonal, referral, etc.';
```

---

## DB 4 Billing — New Tables

### `promotion_claims`

Records each application of a promotion to a specific user. The connection between a user and a promo.

```sql
CREATE TYPE claim_status AS ENUM (
    'pending',       -- Claimed but awaiting trigger (e.g., first listing)
    'active',        -- Currently applied to user
    'converted',     -- Transitioned to paid subscription
    'expired',       -- Promo period ended
    'cancelled'      -- User or admin cancelled
);

CREATE TABLE promotion_claims (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id                 UUID NOT NULL,                   -- DB 1 Identity ref
    promotion_period_id     UUID NOT NULL,                   -- DB 12 Platform ref

    status                  claim_status NOT NULL DEFAULT 'pending',

    -- Applied benefit snapshot (in case promo changes after claim)
    granted_plan_id         UUID,                            -- DB 12 ref
    granted_plan_version_id UUID,                            -- DB 12 ref — immutable version
    duration_days           INTEGER,
    discount_percentage     DECIMAL(5,2),
    discount_amount_cents   INTEGER,

    -- Timing
    activated_at            TIMESTAMP WITH TIME ZONE,
    expires_at              TIMESTAMP WITH TIME ZONE,
    converted_at            TIMESTAMP WITH TIME ZONE,        -- When user converted to paid
    cancelled_at            TIMESTAMP WITH TIME ZONE,

    -- Trigger & source
    trigger_event           VARCHAR(50) NOT NULL,            -- 'signup', 'first_listing', 'promo_code', 'manual_admin'
    promo_code_used         VARCHAR(100),
    referral_source_user_id UUID,                            -- If referral attribution

    -- Reminders
    reminder_30d_sent_at    TIMESTAMP WITH TIME ZONE,
    reminder_7d_sent_at     TIMESTAMP WITH TIME ZONE,
    reminder_1d_sent_at     TIMESTAMP WITH TIME ZONE,

    -- Audit
    applied_by_user_id      UUID,                            -- If manual admin application
    notes                   TEXT,
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_promo_claims_user ON promotion_claims(user_id);
CREATE INDEX idx_promo_claims_promo ON promotion_claims(promotion_period_id);
CREATE INDEX idx_promo_claims_status ON promotion_claims(status, expires_at)
    WHERE status = 'active';
CREATE INDEX idx_promo_claims_expiring ON promotion_claims(expires_at)
    WHERE status = 'active' AND expires_at IS NOT NULL;
CREATE INDEX idx_promo_claims_referral ON promotion_claims(referral_source_user_id)
    WHERE referral_source_user_id IS NOT NULL;

COMMENT ON TABLE promotion_claims IS 'Per-user records of applied promotions with immutable benefit snapshots';
```

### Modifications to existing `subscriptions` table

The existing DB 4 `subscriptions` table needs two additional columns to support versioned plans and promo attribution:

```sql
ALTER TABLE subscriptions
    ADD COLUMN plan_version_id UUID,                         -- DB 12 ref — which plan version user is locked to
    ADD COLUMN active_promotion_claim_id UUID;               -- DB 4 ref — currently-applied promo, if any

CREATE INDEX idx_subscriptions_plan_version ON subscriptions(plan_version_id);
CREATE INDEX idx_subscriptions_active_promo ON subscriptions(active_promotion_claim_id)
    WHERE active_promotion_claim_id IS NOT NULL;

COMMENT ON COLUMN subscriptions.plan_version_id IS 'Immutable plan version — preserves grandfathered pricing when plan definitions change';
COMMENT ON COLUMN subscriptions.active_promotion_claim_id IS 'Currently-applied promotion if any — determines effective pricing';
```

---

## Feature Keys — The Entitlement Registry

The `feature_entitlements.feature_key` column references a well-defined list of features. This registry lives in application code as constants (not database) because adding a new entitlement requires application support.

**Hunter entitlements:**
- `saved_searches_limit` (int, -1 = unlimited)
- `lease_applications_per_season` (int)
- `trail_camera_integration` (bool)
- `digital_id_card` (bool)
- `offline_pwa` (bool)
- `background_checks_per_year` (int)
- `early_listing_access_hours` (int, 0 = none)
- `guided_hunt_discount_pct` (int)
- `concierge_messaging` (bool)
- `trust_badge_level` (string: 'standard', 'enhanced', 'premium', 'veteran')

**Landowner entitlements:**
- `max_active_listings` (int, -1 = unlimited)
- `search_placement` (string: 'standard', 'boosted', 'top')
- `lease_template_tier` (string: 'basic', 'custom', 'attorney')
- `advanced_analytics` (bool)
- `background_check_credits_per_year` (int)
- `photo_uploads_per_listing` (int, -1 = unlimited)
- `video_uploads_per_listing` (int, -1 = unlimited)
- `virtual_tour` (bool)
- `white_label_option` (bool)
- `api_access` (bool)
- `dedicated_support` (bool)

**Club entitlements:**
- `shared_calendar` (bool)
- `stand_assignment` (bool)
- `expense_splitting` (bool)
- `member_voting` (bool)
- `member_announcements` (bool)
- `shared_trail_cams` (bool)
- `guest_pass_tier` (string: 'basic', 'full')

Application code references these constants:

```php
// app/Support/Entitlements.php
class Entitlements
{
    const SAVED_SEARCHES_LIMIT          = 'saved_searches_limit';
    const LEASE_APPLICATIONS_PER_SEASON = 'lease_applications_per_season';
    const TRAIL_CAMERA_INTEGRATION      = 'trail_camera_integration';
    const MAX_ACTIVE_LISTINGS           = 'max_active_listings';
    // ...
}

// Usage
if ($entitlementService->can($user, Entitlements::TRAIL_CAMERA_INTEGRATION)) {
    // show trail cam features
}
```

---

## Entitlement Service (Application Layer)

```php
// app/Services/Platform/EntitlementService.php

class EntitlementService
{
    /**
     * Check if user has a boolean feature enabled.
     */
    public function can(User $user, string $featureKey): bool
    {
        $value = $this->resolve($user, $featureKey);
        return $value === true || (is_numeric($value) && $value !== 0);
    }

    /**
     * Get numeric limit. Returns -1 for unlimited.
     */
    public function limit(User $user, string $featureKey): int
    {
        $value = $this->resolve($user, $featureKey);
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Check if user has capacity for N more of something (e.g., listings).
     */
    public function hasCapacity(User $user, string $featureKey, int $currentCount): bool
    {
        $limit = $this->limit($user, $featureKey);
        return $limit === -1 || $currentCount < $limit;
    }

    /**
     * Resolve entitlement value, respecting promo overrides and Valkey cache.
     */
    private function resolve(User $user, string $featureKey)
    {
        return Cache::store('valkey')->remember(
            "entitlement:{$user->id}:{$featureKey}",
            now()->addMinutes(5),
            function () use ($user, $featureKey) {
                // 1. Check active promotion claim first (highest precedence)
                $activePromoClaim = $this->getActivePromoClaim($user);
                if ($activePromoClaim && $activePromoClaim->granted_plan_version_id) {
                    $version = PlanVersion::find($activePromoClaim->granted_plan_version_id);
                    if ($version && isset($version->entitlements_snapshot[$featureKey])) {
                        return $version->entitlements_snapshot[$featureKey];
                    }
                }

                // 2. Fall back to user's current subscription plan version
                $subscription = $user->activeSubscription;
                if ($subscription && $subscription->plan_version_id) {
                    $version = PlanVersion::find($subscription->plan_version_id);
                    if ($version && isset($version->entitlements_snapshot[$featureKey])) {
                        return $version->entitlements_snapshot[$featureKey];
                    }
                }

                // 3. Default to free-tier entitlement for user's account type
                return $this->defaultForFreeTier($user->account_type, $featureKey);
            }
        );
    }
}
```

Cache invalidation on:
- Subscription creation, change, cancellation
- Promotion claim activation, expiration
- Plan version updates (invalidates all entitlement keys for affected plan)

---

## Migration Order

These tables must be created in this specific order due to foreign key dependencies:

1. `membership_plans` (DB 12) — no dependencies
2. `plan_versions` (DB 12) — depends on `membership_plans`
3. `feature_entitlements` (DB 12) — depends on `membership_plans`
4. `promotional_periods` (DB 12) — depends on `membership_plans` (optional FK)
5. `promotion_claims` (DB 4) — depends on `promotional_periods` (cross-DB UUID, not FK)
6. `ALTER TABLE subscriptions` (DB 4) — add `plan_version_id` and `active_promotion_claim_id` columns

Cross-database references (e.g., `promotion_claims.promotion_period_id` referencing DB 12) are stored as UUIDs **without** foreign key constraints, consistent with the project's cross-database rule.

---

## Initial Seed Data

When these tables are first created, seed them with the launch-default pricing from `membership_tiers.md`:

- 4 Hunter plans (Scout Free, Sportsman, Outfitter, Veteran)
- 3 Landowner plans (Homestead Free, Ranch, Estate)
- 2 Club plans (Basic, Premium)
- 3 Other plans (Outfitter commercial, Consultant, Marketplace Seller)

Plus initial promotional periods:
- Founding Landowner (active, 500 claim limit)
- Landowner Honeymoon (permanent, auto-apply on first listing)
- Veteran Program (permanent, triggered on verification)

The seeders live in `database/seeders/Platform/MembershipPlanSeeder.php` and `PromotionalPeriodSeeder.php`.
