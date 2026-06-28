<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Platform\PromotionalPeriod;
use App\Services\Billing\StripeService;
use App\Services\Platform\EntitlementService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Shared promotion-save side effects for the create/edit pages: flush the
 * entitlement cache, and mirror an active promo's monetary discount to a Stripe
 * Coupon so checkout actually discounts (the period stores the coupon id).
 */
trait SyncsPromotionCoupon
{
    protected function invalidateEntitlements(): void
    {
        app(EntitlementService::class)->invalidateAll();
    }

    /**
     * Sync the period's Stripe Coupon. Only active promotions are mirrored — a
     * draft/scheduled promo gets its coupon when it goes active. Best-effort: a
     * Stripe failure must not block the save, so it surfaces as a warning the
     * admin can act on (re-save or run stripe:sync-promos).
     */
    protected function syncPromotionCoupon(PromotionalPeriod $period): void
    {
        if ($period->status !== 'active') {
            return;
        }

        try {
            app(StripeService::class)->syncPromotionCoupon($period);
        } catch (\Throwable $e) {
            Log::warning('Promotion coupon sync failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            Notification::make()
                ->warning()
                ->title('Stripe coupon not synced')
                ->body('The promotion was saved, but its discount could not be synced to Stripe yet, so checkout will charge full price until it is. Re-save or run stripe:sync-promos.')
                ->persistent()
                ->send();
        }
    }
}
