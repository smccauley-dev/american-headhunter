<?php

namespace App\Console\Commands;

use App\Models\Billing\PromoCode;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Billing\StripeService;
use Illuminate\Console\Command;

/**
 * Pushes the promotional periods that back a promo code (DB 4 → DB 12) to Stripe
 * as Coupons and stores the resulting id on promotional_periods. Hosted Checkout
 * can only discount a subscription via a Stripe Coupon, so an auto-applied promo
 * code needs its period synced here first.
 *
 * Idempotent: a period that already has a stripe_coupon_id is left alone (the id
 * is reused). Periods with no monetary discount (e.g. tier_grant) are skipped.
 *
 *   php artisan stripe:sync-promos
 */
class StripeSyncPromos extends Command
{
    protected $signature   = 'stripe:sync-promos';
    protected $description = 'Sync promo-code promotional periods to Stripe Coupons and store the ids';

    public function handle(StripeService $stripe): int
    {
        $periodIds = PromoCode::on('billing')
            ->pluck('promotional_period_id')
            ->unique()
            ->all();

        $periods = PromotionalPeriod::on('platform')
            ->whereIn('id', $periodIds)
            ->where('status', 'active')
            ->get();

        $rows = [];

        foreach ($periods as $period) {
            $couponId = $stripe->syncPromotionCoupon($period);

            $rows[] = [$period->promo_key, $couponId ?? '— (no discount)'];
        }

        $this->table(['Promotion', 'Stripe Coupon'], $rows);
        $this->info('Synced ' . count($rows) . ' promotion(s) to Stripe.');

        return self::SUCCESS;
    }
}
