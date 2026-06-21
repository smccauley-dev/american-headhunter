<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // SEC-053: trigger-based promotion grants (signup / first_listing) are
        // once per user per period. PromotionAutoApplyService gates this with an
        // existence check, so two concurrent grants could both pass and create a
        // duplicate claim. This partial unique index makes the invariant atomic
        // at the DB. Scoped to trigger grants only — promo_code and manual_admin
        // claims may legitimately repeat for the same user + period.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE UNIQUE INDEX uq_promo_claims_user_period_trigger
                ON promotion_claims (user_id, promotion_period_id)
                WHERE trigger_event IN ('signup', 'first_listing');
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP INDEX IF EXISTS uq_promo_claims_user_period_trigger;');
    }
};
