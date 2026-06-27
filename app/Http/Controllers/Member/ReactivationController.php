<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Billing\MembershipCheckoutService;
use App\Services\Billing\PromotionExpirationService;
use App\Services\Billing\StripeService;
use App\Services\Platform\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reactivation waiting room for a member whose account was paused when a
 * 'pause_account' promotion lapsed (PromotionExpirationService::pauseAccount).
 *
 * A paused member authenticates normally (password + MFA) and is routed here by
 * the login flow; the `auth.session:allow-paused` guard admits them to these
 * billing-only routes while the default active-only guard keeps them out of the
 * rest of /member and /apply. Paying for a membership reactivates the account
 * (subscribe → reactivate), and the next request — now active — reaches the
 * portal normally.
 */
class ReactivationController extends Controller
{
    public function show(Request $request, PlanService $plans): Response|RedirectResponse
    {
        $user = User::findOrFail(session('auth.user_id'));

        // Only paused accounts belong in the waiting room — an already-active
        // member (e.g. the webhook reactivated them in another tab) goes straight
        // to the portal rather than being shown a pay-to-reactivate page.
        if ($user->status !== 'paused') {
            return redirect()->to('/member');
        }

        // Paid, public plans for this account type — the member picks one to
        // resume. A free plan can't lift a billing pause, so they're excluded.
        $available = array_values(array_filter(
            $plans->publicPricing()[$user->account_type] ?? [],
            static fn (array $p): bool => ! ($p['is_default_free'] ?? false)
                && (($p['monthly_price_cents'] ?? 0) > 0 || ($p['annual_price_cents'] ?? 0) > 0),
        ));

        return Inertia::render('Member/Reactivate', [
            'plans'    => $available,
            'checkout' => $request->query('checkout'),
        ]);
    }

    /**
     * Start the hosted Stripe Checkout that resumes the membership. The local
     * subscription is authored on return / by the webhook, not from this redirect.
     */
    public function checkout(Request $request, MembershipCheckoutService $checkout)
    {
        $user = User::findOrFail(session('auth.user_id'));

        $data = $request->validate([
            'plan_key'   => 'required|string',
            'interval'   => 'required|in:monthly,annual',
            'promo_code' => 'nullable|string|max:50',
        ]);

        $result = $checkout->start(
            user:       $user,
            planKey:    $data['plan_key'],
            interval:   $data['interval'],
            promoCode:  $data['promo_code'] ?? null,
            successUrl: route('reactivate.return') . '?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl:  route('reactivate.show') . '?checkout=cancel',
        );

        if (isset($result['error'])) {
            return back()->withErrors([$result['field'] => $result['error']]);
        }

        return Inertia::location($result['url']);
    }

    /**
     * Stripe success return. Reconciles the completed Checkout into a subscription
     * instantly (best-effort — the webhook is the backstop) and lifts the billing
     * pause, so the member lands back in the portal active. Runs as ah_system
     * (db.system) because authoring a subscription is a system write.
     */
    public function return(
        Request $request,
        MembershipCheckoutService $checkout,
        StripeService $stripe,
        PromotionExpirationService $expiration,
    ): RedirectResponse {
        $userId    = session('auth.user_id');
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId !== '') {
            // A Stripe hiccup must not strand the member — the webhook still
            // authors the subscription + reactivates if this misses.
            rescue(function () use ($stripe, $checkout, $expiration, $sessionId, $userId) {
                $session = $stripe->retrieveCheckoutSession($sessionId)->toArray();

                // Only reconcile this member's own checkout, so a mismatched id
                // can't reactivate the wrong account through this URL.
                if (($session['metadata']['user_id'] ?? null) !== $userId) {
                    return;
                }

                if ($checkout->recordSubscriptionFromCheckout($session)) {
                    if ($user = User::find($userId)) {
                        $expiration->reactivate($user);
                    }
                }
            });
        }

        // If the account is now active, the portal is open; otherwise the webhook
        // is still catching up — send them back to the waiting room, which will
        // forward to /member as soon as the pause lifts.
        $user = User::find($userId);
        if ($user && $user->status === 'active') {
            return redirect()->to('/member');
        }

        return redirect()->route('reactivate.show', ['checkout' => 'processing']);
    }
}
