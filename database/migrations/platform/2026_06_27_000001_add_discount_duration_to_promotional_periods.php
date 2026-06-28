<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        // Discount promotions (percentage_discount / dollar_discount) need an
        // explicit Stripe-coupon duration. Before this, a discount promo had no
        // duration field exposed, so upsertCoupon always minted a `once` coupon —
        // the discount only ever applied to the first invoice. These columns let
        // admins choose once / repeating(N months) / forever per promotion.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE promotional_periods
                ADD COLUMN discount_duration VARCHAR(12) NOT NULL DEFAULT 'once',
                ADD COLUMN discount_duration_months SMALLINT NULL;

            ALTER TABLE promotional_periods
                ADD CONSTRAINT promotional_periods_discount_duration_check
                CHECK (discount_duration IN ('once', 'repeating', 'forever'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE promotional_periods
                DROP CONSTRAINT IF EXISTS promotional_periods_discount_duration_check;

            ALTER TABLE promotional_periods
                DROP COLUMN IF EXISTS discount_duration,
                DROP COLUMN IF EXISTS discount_duration_months;
        SQL);
    }
};
