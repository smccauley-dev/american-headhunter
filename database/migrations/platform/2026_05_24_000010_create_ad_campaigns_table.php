<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE ad_campaigns (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                advertiser_user_id  UUID NOT NULL,           -- References DB 1 (Identity) users.id
                campaign_name       VARCHAR(200) NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'draft',
                budget_cents        BIGINT NOT NULL,
                spent_cents         BIGINT NOT NULL DEFAULT 0,
                impression_count    INT NOT NULL DEFAULT 0,
                click_count         INT NOT NULL DEFAULT 0,
                starts_at           DATE NOT NULL,
                ends_at             DATE NOT NULL,
                target_states       JSONB NOT NULL DEFAULT '[]',
                target_species      JSONB NOT NULL DEFAULT '[]',
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
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS ad_campaigns CASCADE');
    }
};
