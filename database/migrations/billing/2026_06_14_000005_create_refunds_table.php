<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE refunds (
                id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                payment_id       UUID         NOT NULL REFERENCES payments (id),
                amount_cents     BIGINT       NOT NULL,
                reason           VARCHAR(100) NULL,
                status           VARCHAR(10)  NOT NULL DEFAULT 'pending'
                                     CHECK (status IN ('pending', 'succeeded', 'failed')),
                stripe_refund_id VARCHAR(100) NULL,
                processed_at     TIMESTAMPTZ  NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_refunds_payment_id    ON refunds (payment_id);
            CREATE INDEX idx_refunds_stripe_refund ON refunds (stripe_refund_id) WHERE stripe_refund_id IS NOT NULL;
            CREATE INDEX idx_refunds_status        ON refunds (status);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS refunds CASCADE;');
    }
};
