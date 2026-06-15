<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE promo_codes (
                id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                promotional_period_id UUID        NOT NULL,  -- References DB 12 (Platform) promotional_periods.id (the benefit definition)
                code                  VARCHAR(50) NOT NULL,
                owner_user_id         UUID        NULL,  -- References DB 1 (Identity) users.id — partner the code is attributed to (outfitter/landowner); null = platform-wide
                max_redemptions       INTEGER     NULL,  -- null = unlimited
                redemption_count      INTEGER     NOT NULL DEFAULT 0,
                per_user_limit        SMALLINT    NOT NULL DEFAULT 1,
                starts_at             TIMESTAMPTZ NULL,
                expires_at            TIMESTAMPTZ NULL,
                is_active             BOOLEAN     NOT NULL DEFAULT true,
                created_by_user_id    UUID        NULL,  -- References DB 1 (Identity) users.id (admin who created it)
                notes                 TEXT        NULL,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at            TIMESTAMPTZ NULL,

                CONSTRAINT chk_promo_codes_redemptions
                    CHECK (redemption_count >= 0
                           AND (max_redemptions IS NULL OR redemption_count <= max_redemptions))
            );

            CREATE UNIQUE INDEX uq_promo_codes_code     ON promo_codes (LOWER(code)) WHERE deleted_at IS NULL;
            CREATE        INDEX idx_promo_codes_period  ON promo_codes (promotional_period_id);
            CREATE        INDEX idx_promo_codes_owner   ON promo_codes (owner_user_id) WHERE owner_user_id IS NOT NULL;
            CREATE        INDEX idx_promo_codes_active  ON promo_codes (is_active) WHERE is_active = true AND deleted_at IS NULL;

            CREATE TRIGGER trg_promo_codes_updated_at
                BEFORE UPDATE ON promo_codes
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS promo_codes CASCADE;');
    }
};
