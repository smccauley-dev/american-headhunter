<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE w9_records (
                id                  UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id             UUID         NOT NULL,  -- References DB 1 (Identity) users.id (the payee)
                legal_name          VARCHAR(200) NOT NULL,
                business_name       VARCHAR(200) NULL,
                tax_classification  VARCHAR(20)  NOT NULL
                                        CHECK (tax_classification IN
                                            ('individual','sole_proprietor','c_corp','s_corp',
                                             'partnership','trust_estate','llc')),
                tin_type            VARCHAR(3)   NOT NULL CHECK (tin_type IN ('ssn','ein')),
                tin                 TEXT         NOT NULL,  -- pgp_sym_encrypt base64 (Key D) via HasEncryptedFields; in $hidden — never exposed
                tin_last_four       CHAR(4)      NOT NULL,  -- display-safe only
                address_line1       VARCHAR(200) NOT NULL,
                address_line2       VARCHAR(200) NULL,
                city                VARCHAR(100) NOT NULL,
                state_code          CHAR(2)      NOT NULL,
                postal_code         VARCHAR(10)  NOT NULL,
                backup_withholding  BOOLEAN      NOT NULL DEFAULT false,
                status              VARCHAR(15)  NOT NULL DEFAULT 'pending'
                                        CHECK (status IN ('pending','verified','invalid','superseded')),
                certified_at        TIMESTAMPTZ  NULL,  -- when the payee e-signed/certified the W-9
                verified_at         TIMESTAMPTZ  NULL,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
                -- No deleted_at — tax compliance record; a re-collected W-9 supersedes the old one (status='superseded')
            );

            CREATE UNIQUE INDEX uq_w9_records_user_active ON w9_records (user_id)
                WHERE status IN ('pending','verified');
            CREATE        INDEX idx_w9_records_user_id    ON w9_records (user_id);
            CREATE        INDEX idx_w9_records_status     ON w9_records (status);

            CREATE TRIGGER trg_w9_records_updated_at
                BEFORE UPDATE ON w9_records
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE w9_records ENABLE ROW LEVEL SECURITY;

            CREATE POLICY w9_records_own_user ON w9_records
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS w9_records CASCADE;');
    }
};
