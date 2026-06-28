<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Billing\MembershipCheckoutService;
use App\Services\Billing\StripeService;
use Illuminate\Http\RedirectResponse;
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
            successUrl: route('member.membership.checkout.return') . '?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl:  route('member.membership') . '?checkout=cancel',
        );

        if (isset($result['error'])) {
            return back()->withErrors([$result['field'] => $result['error']]);
        }

        return Inertia::location($result['url']);
    }

    /**
     * Stripe success return. Reconciles the completed Checkout into the local
     * subscription instantly so the membership tab reflects the new plan on this
     * very render instead of showing the old (free) plan until the async webhook
     * lands. Best-effort — the webhook is the backstop. Runs as ah_system
     * (db.system) because authoring a subscription is a system write.
     */
    public function return(Request $request, StripeService $stripe): RedirectResponse
    {
        $userId    = session('auth.user_id');
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId !== '') {
            // A Stripe hiccup must not strand the member — the webhook still
            // authors the subscription if this misses.
            rescue(function () use ($stripe, $sessionId, $userId) {
                $session = $stripe->retrieveCheckoutSession($sessionId)->toArray();

                // Only reconcile this member's own checkout, so a mismatched id
                // can't author a subscription for the wrong account through this URL.
                if (($session['metadata']['user_id'] ?? null) !== $userId) {
                    return;
                }

                $this->checkout->recordSubscriptionFromCheckout($session);
            });
        }

        return redirect()->to(route('member.membership') . '?checkout=success');
    }
}
