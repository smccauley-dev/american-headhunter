<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TYPE promotion_type AS ENUM (
                'tier_grant',
                'percentage_discount',
                'dollar_discount',
                'free_period',
                'referral_program',
                'promo_code_campaign'
            );

            CREATE TYPE promotion_status AS ENUM (
                'draft',
                'scheduled',
                'active',
                'paused',
                'exhausted',
                'expired',
                'ended'
            );

            CREATE TABLE promotional_periods (
                id                          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                promo_key                   VARCHAR(80) NOT NULL,
                display_name                VARCHAR(200) NOT NULL,
                description                 TEXT,

                promotion_type              promotion_type NOT NULL,
                status                      promotion_status NOT NULL DEFAULT 'draft',

                target_account_types        TEXT[] NOT NULL DEFAULT '{}',
                target_states               TEXT[],
                target_rules_json           JSONB,

                grants_plan_id              UUID REFERENCES membership_plans (id),
                duration_days               INTEGER,
                discount_percentage         DECIMAL(5,2),
                discount_amount_cents       INTEGER,
                referral_reward_type        VARCHAR(30),
                referral_reward_value       INTEGER,

                on_expiration               VARCHAR(30) NOT NULL DEFAULT 'downgrade_free',

                starts_at                   TIMESTAMPTZ,
                ends_at                     TIMESTAMPTZ,
                claim_limit                 INTEGER,
                claim_count                 INTEGER NOT NULL DEFAULT 0,
                per_user_limit              INTEGER NOT NULL DEFAULT 1,

                stackable_with_other_promos BOOLEAN NOT NULL DEFAULT false,
                stackable_with_veteran      BOOLEAN NOT NULL DEFAULT true,
                requires_promo_code         BOOLEAN NOT NULL DEFAULT false,
                auto_apply_on_signup        BOOLEAN NOT NULL DEFAULT false,
                auto_apply_on_first_listing BOOLEAN NOT NULL DEFAULT false,

                show_on_landing             BOOLEAN NOT NULL DEFAULT false,
                show_on_pricing             BOOLEAN NOT NULL DEFAULT false,
                show_claim_counter          BOOLEAN NOT NULL DEFAULT false,
                landing_banner_text         TEXT,
                pricing_badge_text          VARCHAR(100),
                dashboard_callout_text      TEXT,

                created_by_user_id          UUID,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                paused_at                   TIMESTAMPTZ,
                ended_at                    TIMESTAMPTZ,

                CONSTRAINT uq_promotional_periods_key UNIQUE (promo_key),
                CONSTRAINT chk_promotional_periods_on_expiration
                    CHECK (on_expiration IN ('auto_charge', 'downgrade_free', 'pause_account'))
            );

            CREATE INDEX idx_promo_periods_status ON promotional_periods (status)
                WHERE status IN ('active', 'scheduled');
            CREATE INDEX idx_promo_periods_dates ON promotional_periods (starts_at, ends_at)
                WHERE status = 'active';
            CREATE INDEX idx_promo_periods_auto_apply ON promotional_periods (auto_apply_on_signup, auto_apply_on_first_listing)
                WHERE status = 'active';

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON promotional_periods
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            -- Founding Landowner — 500-slot founding member deal
            INSERT INTO promotional_periods (
                promo_key, display_name, description, promotion_type, status,
                target_account_types, grants_plan_id, duration_days, on_expiration,
                claim_limit, per_user_limit,
                show_on_landing, show_on_pricing, show_claim_counter,
                landing_banner_text, pricing_badge_text, dashboard_callout_text
            )
            SELECT
                'founding_landowner_2026',
                'Founding Landowner',
                '90 days of Ranch free for the first 500 landowners to join',
                'tier_grant',
                'active',
                ARRAY['landowner'],
                id,
                90,
                'downgrade_free',
                500,
                1,
                true, true, true,
                'Be one of our 500 Founding Landowners — 90 days Ranch free.',
                'Founding Member',
                'You are a Founding Landowner. Your Ranch benefits are active for 90 days.'
            FROM membership_plans WHERE plan_key = 'landowner_ranch';

            -- Landowner Honeymoon — auto-applied to every new landowner on first listing
            INSERT INTO promotional_periods (
                promo_key, display_name, description, promotion_type, status,
                target_account_types, grants_plan_id, duration_days, on_expiration,
                auto_apply_on_first_listing,
                show_on_pricing, pricing_badge_text
            )
            SELECT
                'landowner_honeymoon_permanent',
                'Landowner Honeymoon',
                '30 days Ranch free when you publish your first listing',
                'tier_grant',
                'active',
                ARRAY['landowner'],
                id,
                30,
                'downgrade_free',
                true,
                true,
                '30 Days Free'
            FROM membership_plans WHERE plan_key = 'landowner_ranch';

            -- Veteran Program — permanent, triggered on veteran verification
            INSERT INTO promotional_periods (
                promo_key, display_name, description, promotion_type, status,
                target_account_types, grants_plan_id, on_expiration,
                stackable_with_veteran,
                show_on_pricing, pricing_badge_text
            )
            SELECT
                'veteran_hunter_permanent',
                'Veteran Hunter Program',
                'Active-duty and veteran hunters receive Sportsman tier free for life',
                'tier_grant',
                'active',
                ARRAY['hunter'],
                id,
                'downgrade_free',
                true,
                true,
                'Veteran — Free for Life'
            FROM membership_plans WHERE plan_key = 'hunter_veteran';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS promotional_periods CASCADE;
            DROP TYPE IF EXISTS promotion_status;
            DROP TYPE IF EXISTS promotion_type;
        SQL);
    }
};
