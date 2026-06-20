<?php

namespace App\Console\Commands;

use App\Models\Platform\MembershipPlan;
use App\Services\Billing\StripeService;
use Illuminate\Console\Command;

/**
 * Pushes membership plans (DB 12) to Stripe as Products + recurring Prices and
 * stores the resulting ids back on membership_plans. Prices live on the plan
 * (not plan_versions, which are UPDATE-blocked by a Postgres rule); grandfathering
 * is handled by Stripe holding the original price on each existing subscription.
 *
 * Idempotent: an existing product is updated in place; an interval whose price id
 * is already stored is left alone unless --refresh is passed (which mints a fresh
 * Price for every enabled interval — use after a price change).
 *
 *   php artisan stripe:sync-plans
 *   php artisan stripe:sync-plans --refresh
 */
class StripeSyncPlans extends Command
{
    protected $signature   = 'stripe:sync-plans {--refresh : Create new Prices for all enabled intervals even if one is already stored}';
    protected $description = 'Sync membership plans to Stripe Products/Prices and store the ids';

    public function handle(StripeService $stripe): int
    {
        $refresh = (bool) $this->option('refresh');
        $plans   = MembershipPlan::query()->where('is_active', true)->orderBy('sort_order')->get();

        $rows = [];

        foreach ($plans as $plan) {
            $plan->stripe_product_id = $stripe->upsertProduct($plan);

            $monthly = $this->syncInterval($stripe, $plan, 'monthly', $plan->monthly_price_cents, (bool) $plan->monthly_enabled, $refresh);
            $annual  = $this->syncInterval($stripe, $plan, 'annual',  $plan->annual_price_cents,  (bool) $plan->annual_enabled,  $refresh);

            $plan->save();

            $rows[] = [$plan->plan_key, $plan->stripe_product_id, $monthly, $annual];
        }

        $this->table(['Plan', 'Product', 'Monthly price', 'Annual price'], $rows);
        $this->info('Synced ' . count($rows) . ' plan(s) to Stripe.');

        return self::SUCCESS;
    }

    /**
     * Returns a human-readable status for the interval and mutates the plan's
     * stripe_{interval}_price_id when a price is created.
     */
    private function syncInterval(
        StripeService $stripe,
        MembershipPlan $plan,
        string $interval,
        int $cents,
        bool $enabled,
        bool $refresh,
    ): string {
        $column = "stripe_{$interval}_price_id";

        if (! $enabled || $cents <= 0) {
            return $enabled ? 'free' : 'disabled';
        }

        if ($plan->{$column} && ! $refresh) {
            return 'kept';
        }

        $plan->{$column} = $stripe->createPrice($plan->stripe_product_id, $cents, $interval, $plan->currency ?? 'USD');

        return 'created';
    }
}
