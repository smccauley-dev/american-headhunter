<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.5 — exact processing-fee recovery (DB 4 fee_schedules).
 *
 * A naive surcharge of pct×base under-recovers: the platform is the merchant of
 * record, so Stripe charges its fee on the *gross* the customer pays (base +
 * surcharge), not on the base. `gross_up` flags a row as a pass-through processor
 * fee that should be grossed up so the customer fully covers the fee on the total
 * charge — FeeService then returns ceil((pct×base + flat) / (1 − pct)) instead of
 * round(pct×base) + flat. Default false keeps every existing row a flat markup.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE fee_schedules ADD COLUMN gross_up BOOLEAN NOT NULL DEFAULT false'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE fee_schedules DROP COLUMN IF EXISTS gross_up'
        );
    }
};
