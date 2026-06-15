<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // Per-user records of applied promotions with immutable benefit snapshots.
        // promotion_period_id / granted_plan_id / granted_plan_version_id are cross-DB
        // UUID refs into DB 12 (Platform) — no FK constraints.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TYPE claim_status AS ENUM (
                'pending',
                'active',
                'converted',
                'expired',
                'cancelled'
            );

            CREATE TABLE promotion_claims (
                id                      UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                 UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                promotion_period_id     UUID         NOT NULL,  -- References DB 12 (Platform) promotional_periods.id

                status                  claim_status NOT NULL DEFAULT 'pending',

                granted_plan_id         UUID         NULL,  -- References DB 12 (Platform) membership_plans.id
                granted_plan_version_id UUID         NULL,  -- References DB 12 (Platform) plan_versions.id
                duration_days           INTEGER      NULL,
                discount_percentage     DECIMAL(5,2) NULL,
                discount_amount_cents   INTEGER      NULL,

                activated_at            TIMESTAMPTZ  NULL,
                expires_at              TIMESTAMPTZ  NULL,
                converted_at            TIMESTAMPTZ  NULL,
                cancelled_at            TIMESTAMPTZ  NULL,

                trigger_event           VARCHAR(50)  NOT NULL,  -- 'signup', 'first_listing', 'promo_code', 'manual_admin'
                promo_code_used         VARCHAR(100) NULL,
                referral_source_user_id UUID         NULL,      -- References DB 1 (Identity) users.id

                reminder_30d_sent_at    TIMESTAMPTZ  NULL,
                reminder_7d_sent_at     TIMESTAMPTZ  NULL,
                reminder_1d_sent_at     TIMESTAMPTZ  NULL,

                applied_by_user_id      UUID         NULL,      -- References DB 1 (Identity) users.id (manual admin)
                notes                   TEXT         NULL,
                created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_promo_claims_user   ON promotion_claims (user_id);
            CREATE INDEX idx_promo_claims_promo  ON promotion_claims (promotion_period_id);
            CREATE INDEX idx_promo_claims_status ON promotion_claims (status, expires_at)
                WHERE status = 'active';
            CREATE INDEX idx_promo_claims_expiring ON promotion_claims (expires_at)
                WHERE status = 'active' AND expires_at IS NOT NULL;
            CREATE INDEX idx_promo_claims_referral ON promotion_claims (referral_source_user_id)
                WHERE referral_source_user_id IS NOT NULL;

            CREATE TRIGGER trg_promotion_claims_updated_at
                BEFORE UPDATE ON promotion_claims
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS promotion_claims CASCADE;
            DROP TYPE IF EXISTS claim_status;
        SQL);
    }
};
