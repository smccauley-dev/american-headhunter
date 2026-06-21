<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE promotional_periods ADD COLUMN stripe_coupon_id VARCHAR(100) NULL;'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE promotional_periods DROP COLUMN IF EXISTS stripe_coupon_id;'
        );
    }
};
