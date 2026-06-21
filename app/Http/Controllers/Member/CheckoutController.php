<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Billing\MembershipCheckoutService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Starts a hosted Stripe Checkout for a membership subscription. This endpoint
 * only creates the Checkout Session and redirects the browser to Stripe — the
 * local subscription is written later by ProcessStripeWebhook when Stripe fires
 * checkout.session.completed (the redirect itself is never trusted).
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly MembershipCheckoutService $checkout,
    ) {}

    public function create(Request $request)
    {
        $user = User::findOrFail(session('auth.user_id'));

        $data = $request->validate([
            'plan_key'   => 'required|string',
            'interval'   => 'required|in:monthly,annual',
            'promo_code' => 'nullable|string|max:50',
        ]);

        $result = $this->checkout->start(
            user:       $user,
            planKey:    $data['plan_key'],
            interval:   $data['interval'],
            promoCode:  $data['promo_code'] ?? null,
            successUrl: route('member.membership') . '?checkout=success',
            cancelUrl:  route('member.membership') . '?checkout=cancel',
        );

        if (isset($result['error'])) {
            return back()->withErrors([$result['field'] => $result['error']]);
        }

        return Inertia::location($result['url']);
    }
}
