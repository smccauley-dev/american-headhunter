<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pricing callouts are publishable horizontal banners shown beneath the plan
 * cards on a given pricing tab (e.g. the "Veteran or First Responder?" banner on
 * the Hunters tab). They are NOT purchasable plans — no price, no checkout — so
 * they live in their own table rather than overloading membership_plans: a
 * callout just carries copy, optional feature bullets, and a single CTA link, and
 * an is_published flag toggles it on the public page.
 *
 * Seeds the existing hard-coded Veteran/First Responder banner as one published
 * row so the public page is unchanged once the JSX is replaced by this data.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE pricing_callouts (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                account_type VARCHAR(20)  NOT NULL,  -- which pricing tab it appears under
                eyebrow      VARCHAR(80),
                body         TEXT         NOT NULL,
                features     JSONB        NOT NULL DEFAULT '[]',  -- [{label, description}]
                cta_label    VARCHAR(40),
                cta_url      VARCHAR(255),
                accent_color VARCHAR(9),
                is_published BOOLEAN      NOT NULL DEFAULT false,
                sort_order   INTEGER      NOT NULL DEFAULT 0,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_pricing_callouts_account_type ON pricing_callouts (account_type);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON pricing_callouts
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            INSERT INTO pricing_callouts
                (account_type, eyebrow, body, cta_label, cta_url, is_published, sort_order)
            SELECT
                'hunter',
                'Veteran or First Responder?',
                'Thank you for your service. Verify your status when you sign up — once approved, your Hunter membership is free, for life.',
                'Verify & Join',
                '/get-started?type=hunter',
                true,
                0
            WHERE NOT EXISTS (
                SELECT 1 FROM pricing_callouts
                WHERE account_type = 'hunter' AND eyebrow = 'Veteran or First Responder?'
            );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS pricing_callouts CASCADE;');
    }
};
