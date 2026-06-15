<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // No deleted_at — tax records are permanent compliance documents.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE tax_1099_records (
                id                 UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                payee_user_id      UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                tax_year           SMALLINT     NOT NULL,
                form_type          VARCHAR(10)  NOT NULL
                                       CHECK (form_type IN ('1099_nec', '1099_k')),
                gross_amount_cents BIGINT       NOT NULL,
                status             VARCHAR(15)  NOT NULL DEFAULT 'pending'
                                       CHECK (status IN ('pending', 'filed', 'corrected')),
                tax1099_record_id  VARCHAR(100) NULL,  -- Tax1099 API record ID
                filed_at           TIMESTAMPTZ  NULL,
                created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_tax_1099_payee_year_type ON tax_1099_records (payee_user_id, tax_year, form_type);
            CREATE        INDEX idx_tax_1099_payee_user_id  ON tax_1099_records (payee_user_id);
            CREATE        INDEX idx_tax_1099_tax_year       ON tax_1099_records (tax_year);
            CREATE        INDEX idx_tax_1099_status         ON tax_1099_records (status);

            CREATE TRIGGER trg_tax_1099_records_updated_at
                BEFORE UPDATE ON tax_1099_records
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS tax_1099_records CASCADE;');
    }
};
