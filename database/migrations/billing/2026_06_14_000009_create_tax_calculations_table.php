<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // Append-only — no updated_at, no deleted_at, no update trigger.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE tax_calculations (
                id                    UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                payment_id            UUID         NOT NULL REFERENCES payments (id),
                taxjar_transaction_id VARCHAR(100) NULL,
                state_code            CHAR(2)      NOT NULL,
                tax_rate              NUMERIC(6,4) NOT NULL,  -- e.g., 0.0875 for 8.75%
                amount_taxable_cents  BIGINT       NOT NULL,
                tax_cents             BIGINT       NOT NULL,
                created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_tax_calculations_payment ON tax_calculations (payment_id);
            CREATE        INDEX idx_tax_calculations_state  ON tax_calculations (state_code);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS tax_calculations CASCADE;');
    }
};
