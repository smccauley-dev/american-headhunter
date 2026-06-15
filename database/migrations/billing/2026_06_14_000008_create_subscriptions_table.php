<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // plan_version_id and active_promotion_claim_id are included directly here
        // (rather than via a later ALTER) per docs/pricing_schema_additions.md, since
        // this is a fresh build. Both are cross-DB UUID refs — no FK constraints.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE subscriptions (
                id                        UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                   UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                plan_version_id           UUID         NOT NULL,  -- References DB 12 (Platform) plan_versions.id
                active_promotion_claim_id UUID         NULL,      -- References DB 4 promotion_claims.id — current promo, if any
                stripe_subscription_id    VARCHAR(100) NULL,
                stripe_customer_id        VARCHAR(100) NULL,
                status                    VARCHAR(15)  NOT NULL DEFAULT 'active'
                                              CHECK (status IN ('active', 'trialing', 'past_due', 'cancelled', 'unpaid')),
                current_period_start      DATE         NOT NULL,
                current_period_end        DATE         NOT NULL,
                trial_ends_at             TIMESTAMPTZ  NULL,
                cancelled_at              TIMESTAMPTZ  NULL,
                created_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_subscriptions_user_active ON subscriptions (user_id)
                WHERE status IN ('active', 'trialing', 'past_due');
            CREATE INDEX idx_subscriptions_user_id         ON subscriptions (user_id);
            CREATE INDEX idx_subscriptions_plan_version_id ON subscriptions (plan_version_id);
            CREATE INDEX idx_subscriptions_status          ON subscriptions (status);
            CREATE INDEX idx_subscriptions_stripe_sub_id   ON subscriptions (stripe_subscription_id)
                WHERE stripe_subscription_id IS NOT NULL;
            CREATE INDEX idx_subscriptions_period_end      ON subscriptions (current_period_end);
            CREATE INDEX idx_subscriptions_active_promo     ON subscriptions (active_promotion_claim_id)
                WHERE active_promotion_claim_id IS NOT NULL;

            CREATE TRIGGER trg_subscriptions_updated_at
                BEFORE UPDATE ON subscriptions
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS subscriptions CASCADE;');
    }
};
