<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE plan_promo_codes (
                id                   UUID    NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                plan_id              UUID    NOT NULL REFERENCES membership_plans (id) ON DELETE CASCADE,
                promo_code_id        UUID    NOT NULL,  -- References DB 4 (Billing) promo_codes.id
                show_on_pricing_card BOOLEAN NOT NULL DEFAULT false,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_plan_promo_codes UNIQUE (plan_id, promo_code_id)
            );

            CREATE INDEX idx_plan_promo_codes_code ON plan_promo_codes (promo_code_id);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON plan_promo_codes
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS plan_promo_codes CASCADE;');
    }
};
