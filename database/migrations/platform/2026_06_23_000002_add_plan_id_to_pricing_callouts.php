<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optional, display-only link from a pricing callout to a membership plan. When
 * set, the public callout surfaces that plan's live (current published version)
 * price on the banner so the marketing copy stays in sync with the real plan —
 * it grants nothing. Entitlements still flow only through verification/promo →
 * promotional_periods.grants_plan_id, never through a callout.
 *
 * Same-database reference (both tables live in DB 12 platform), so a real FK is
 * allowed; ON DELETE SET NULL drops the link if the plan is hard-deleted.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE pricing_callouts
                ADD COLUMN plan_id UUID NULL
                REFERENCES membership_plans (id) ON DELETE SET NULL;

            CREATE INDEX idx_pricing_callouts_plan_id ON pricing_callouts (plan_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_pricing_callouts_plan_id;
            ALTER TABLE pricing_callouts DROP COLUMN IF EXISTS plan_id;
        SQL);
    }
};
