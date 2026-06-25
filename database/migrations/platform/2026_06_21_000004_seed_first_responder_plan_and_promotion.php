<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The First Responder benefit mirrors the Veteran one: a verified first
 * responder receives a free-for-life Hunter membership, granted on verification
 * via ServiceVerificationService (which resolves the promo by the DB-12
 * `verification.first_responder.promo_key` setting = first_responder_hunter_permanent).
 *
 * Rather than hand first responders the "Veteran" tier, this clones the veteran
 * plan into a parallel `hunter_first_responder` plan — same price/version/
 * entitlements, but its own labels and a 'first_responder' trust badge — then
 * seeds the granting promotional_period. The plan is is_public=false (earned by
 * verifying, never bought), so it never shows as a pricing card. The promo has
 * no auto-apply flag, so it only grants on approved verification.
 *
 * Idempotent guards skip each insert if the row already exists, so re-running on
 * a database that already has the rows is safe.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- 1. Clone the veteran plan into a parallel first-responder plan.
            --    Stripe product id and header image are intentionally not copied.
            INSERT INTO membership_plans (
                plan_key, account_type, display_name, description, tagline,
                monthly_price_cents, annual_price_cents, currency, platform_fee_pct, commission_pct,
                monthly_enabled, annual_enabled, sort_order, is_public, is_active, is_default_free, launched_at
            )
            SELECT
                'hunter_first_responder', account_type, 'First Responder', description, 'In honor of your service',
                monthly_price_cents, annual_price_cents, currency, platform_fee_pct, commission_pct,
                monthly_enabled, annual_enabled, 41, false, true, false, NOW()
            FROM membership_plans
            WHERE plan_key = 'hunter_veteran'
              AND NOT EXISTS (SELECT 1 FROM membership_plans WHERE plan_key = 'hunter_first_responder');

            -- 2. Clone the current plan version (snapshot's "veteran" badge → "first_responder").
            INSERT INTO plan_versions (
                plan_id, version_number, plan_key, display_name,
                monthly_price_cents, annual_price_cents, platform_fee_pct, commission_pct,
                entitlements_snapshot, effective_from, change_reason
            )
            SELECT
                fr.id, v.version_number, 'hunter_first_responder', 'First Responder',
                v.monthly_price_cents, v.annual_price_cents, v.platform_fee_pct, v.commission_pct,
                REPLACE(v.entitlements_snapshot::text, '"veteran"', '"first_responder"')::jsonb,
                NOW(), 'Initial version'
            FROM plan_versions v
            JOIN membership_plans vp ON vp.id = v.plan_id AND vp.plan_key = 'hunter_veteran'
            JOIN membership_plans fr ON fr.plan_key = 'hunter_first_responder'
            WHERE NOT EXISTS (SELECT 1 FROM plan_versions pv WHERE pv.plan_id = fr.id);

            -- 3. Clone the feature entitlements.
            INSERT INTO feature_entitlements (
                plan_id, feature_key, feature_type, bool_value, int_value, string_value, json_value,
                display_label, display_description, display_order, show_on_pricing
            )
            SELECT
                fr.id, e.feature_key, e.feature_type, e.bool_value, e.int_value, e.string_value, e.json_value,
                e.display_label, e.display_description, e.display_order, e.show_on_pricing
            FROM feature_entitlements e
            JOIN membership_plans vp ON vp.id = e.plan_id AND vp.plan_key = 'hunter_veteran'
            JOIN membership_plans fr ON fr.plan_key = 'hunter_first_responder'
            WHERE NOT EXISTS (SELECT 1 FROM feature_entitlements fe WHERE fe.plan_id = fr.id);

            -- 4. Re-badge the trust level for first responders.
            UPDATE feature_entitlements
            SET string_value = 'first_responder', display_label = 'First responder trust badge'
            WHERE feature_key = 'trust_badge_level'
              AND plan_id = (SELECT id FROM membership_plans WHERE plan_key = 'hunter_first_responder');

            -- 5. Seed the granting promotional period (no auto-apply: verification only).
            INSERT INTO promotional_periods (
                promo_key, display_name, description, promotion_type, status,
                target_account_types, grants_plan_id, on_expiration,
                show_on_pricing, pricing_badge_text
            )
            SELECT
                'first_responder_hunter_permanent', 'First Responder Hunter Program',
                'Verified first responders receive Sportsman tier free for life',
                'tier_grant', 'active', ARRAY['hunter'], id, 'downgrade_free',
                true, 'First Responder — Free for Life'
            FROM membership_plans
            WHERE plan_key = 'hunter_first_responder'
              AND NOT EXISTS (SELECT 1 FROM promotional_periods WHERE promo_key = 'first_responder_hunter_permanent');
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DELETE FROM promotional_periods WHERE promo_key = 'first_responder_hunter_permanent';
            DELETE FROM feature_entitlements
                WHERE plan_id = (SELECT id FROM membership_plans WHERE plan_key = 'hunter_first_responder');
            DELETE FROM plan_versions
                WHERE plan_id = (SELECT id FROM membership_plans WHERE plan_key = 'hunter_first_responder');
            DELETE FROM membership_plans WHERE plan_key = 'hunter_first_responder';
        SQL);
    }
};
