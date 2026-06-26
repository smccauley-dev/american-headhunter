<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Billing\PayoutService;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Landowner Stripe Connect onboarding — the front door to receiving payouts.
 *
 * Only landowners receive lease revenue, so only they onboard. The connect/refresh
 * actions create the Connect account row (a stripe_accounts write) and must run
 * under ah_system — their routes are wrapped in db.system (SEC-055). Stripe hosts
 * the actual identity/bank onboarding; the account.updated webhook flips
 * payouts_enabled when it completes. The return URL is informational only.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    /** Begin onboarding: mint/resume the Connect account and redirect to Stripe. */
    public function connect()
    {
        $user = $this->landownerOrAbort();

        $url = $this->payouts->startOnboarding(
            landowner:  $user,
            returnUrl:  route('member.payouts.return'),
            refreshUrl: route('member.payouts.refresh'),
        );

        return Inertia::location($url);
    }

    /**
     * Stripe redirects here if the onboarding link expired before the landowner
     * finished — mint a fresh link and send them straight back in.
     */
    public function refresh()
    {
        $user = $this->landownerOrAbort();

        try {
            $url = $this->payouts->startOnboarding(
                landowner:  $user,
                returnUrl:  route('member.payouts.return'),
                refreshUrl: route('member.payouts.refresh'),
            );

            return Inertia::location($url);
        } catch (\Throwable $e) {
            Log::warning('Connect onboarding refresh failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return redirect()->to(route('member.profile') . '?payouts=error');
        }
    }

    /**
     * Stripe redirects here when the landowner returns from onboarding. Stripe is
     * the source of truth (the webhook syncs payouts_enabled), so this only sends
     * them back to My Properties with a status flag for the banner — mirroring the
     * existing ?checkout= / ?billing= query convention the profile page reads.
     */
    public function return()
    {
        $user  = $this->landownerOrAbort();
        $state = $this->payouts->onboardingState($user);

        return redirect()->to(route('member.profile') . '?payouts=' . ($state['onboarded'] ? 'complete' : 'pending'));
    }

    private function landownerOrAbort(): User
    {
        $user = User::findOrFail(session('auth.user_id'));

        abort_unless($user->account_type === 'landowner', 403, 'Only landowners can set up payouts.');

        return $user;
    }
}
