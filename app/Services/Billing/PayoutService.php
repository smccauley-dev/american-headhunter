<?php

namespace App\Services\Billing;

use App\Models\Billing\Payout;
use App\Models\Billing\PromotionClaim;
use App\Models\Billing\StripeAccount;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Carbon;

/**
 * Stripe Connect landowner payouts (DB 4) — fee resolution and disbursement.
 *
 * The platform takes a fee on each lease payment that varies by the landowner's
 * tier (plan_versions.platform_fee_pct, grandfathered to their locked version;
 * the live plan's fee for free-tier landowners). The remainder is transferred to
 * the landowner's Connect account and recorded as a payout.
 *
 * Payouts are system-authored: the payouts table is runtime-read-only (RLS,
 * SEC-045) and stripe_accounts is now likewise (this migration). Every write here
 * runs under ah_system — the disburse job (queue worker), the admin panel, and
 * console commands all resolve to that role.
 */
class PayoutService extends BaseService
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly AuditService  $audit,
    ) {}

    // ── Fee resolution ───────────────────────────────────────────────────────────

    /**
     * The platform fee percentage for a landowner, resolved in the same precedence
     * order as entitlements: an active promotion claim's granted version, then the
     * active subscription's locked version, then the account type's free-tier plan.
     * A version that does not carry its own fee falls back to its parent plan's.
     */
    public function platformFeePct(User $landowner): float
    {
        $claim = $this->activePromotionClaim($landowner->id);
        if ($claim && $claim->granted_plan_version_id) {
            $pct = $this->feePctFromVersion($claim->granted_plan_version_id);
            if ($pct !== null) {
                return $pct;
            }
        }

        $subscription = $this->activeSubscription($landowner->id);
        if ($subscription && $subscription->plan_version_id) {
            $pct = $this->feePctFromVersion($subscription->plan_version_id);
            if ($pct !== null) {
                return $pct;
            }
        }

        return $this->freeTierFeePct($landowner->account_type);
    }

    /**
     * Break a gross lease payment into the platform fee and the landowner's net.
     *
     * @return array{gross_cents:int, fee_pct:float, fee_cents:int, net_cents:int}
     */
    public function quote(User $landowner, int $grossCents): array
    {
        $pct      = $this->platformFeePct($landowner);
        $feeCents = (int) round($grossCents * $pct / 100);

        return [
            'gross_cents' => $grossCents,
            'fee_pct'     => $pct,
            'fee_cents'   => $feeCents,
            'net_cents'   => $grossCents - $feeCents,
        ];
    }

    // ── Connect account ──────────────────────────────────────────────────────────

    /** The landowner's Stripe Connect account row, or null when they have none. */
    public function connectAccount(User $landowner): ?StripeAccount
    {
        return StripeAccount::where('user_id', $landowner->id)->first();
    }

    /** Whether the landowner has a Connect account that can receive payouts. */
    public function canReceivePayouts(User $landowner): bool
    {
        $account = $this->connectAccount($landowner);

        return $account !== null && $account->payouts_enabled;
    }

    /**
     * The landowner's onboarding status, shaped for the member UI. `onboarded` is
     * the only flag that gates real money — it tracks the authoritative
     * payouts_enabled the account.updated webhook syncs from Stripe.
     *
     * @return array{connected:bool, charges_enabled:bool, payouts_enabled:bool, details_submitted:bool, onboarded:bool}
     */
    public function onboardingState(User $landowner): array
    {
        $account = $this->connectAccount($landowner);

        return [
            'connected'         => $account !== null,
            'charges_enabled'   => (bool) $account?->charges_enabled,
            'payouts_enabled'   => (bool) $account?->payouts_enabled,
            'details_submitted' => (bool) $account?->details_submitted,
            'onboarded'         => $account !== null && $account->payouts_enabled,
        ];
    }

    /**
     * Start (or resume) Stripe Connect onboarding for a landowner and return the
     * hosted onboarding URL to redirect them to. The Connect account row is created
     * once and persisted here; the account.updated webhook later flips its flags
     * when Stripe confirms the landowner finished. Writes stripe_accounts, so this
     * must run under ah_system (the route is wrapped in db.system, SEC-055).
     */
    public function startOnboarding(User $landowner, string $returnUrl, string $refreshUrl): string
    {
        $account = $this->connectAccount($landowner);

        if ($account === null) {
            $stripeAccountId = $this->stripe->createConnectAccount($landowner);

            $account = StripeAccount::create([
                'user_id'           => $landowner->id,
                'stripe_account_id' => $stripeAccountId,
                'charges_enabled'   => false,
                'payouts_enabled'   => false,
                'details_submitted' => false,
            ]);

            $this->audit->log(
                eventType:      'stripe_account.created',
                sourceDatabase: 'ah_billing',
                tableName:      'stripe_accounts',
                recordId:       $account->id,
                userId:         $landowner->id,
                actionSummary:  'Stripe Connect account created for landowner payout onboarding',
            );
        }

        return $this->stripe->createAccountLink($account->stripe_account_id, $refreshUrl, $returnUrl);
    }

    // ── Disbursement (ah_system) ─────────────────────────────────────────────────

    /**
     * Transfer a landowner's net lease revenue to their Connect account and record
     * the payout. The platform fee for their tier is withheld; the remainder is moved
     * with a synchronous Stripe Transfer (platform balance → the connected account's
     * balance), so the payout is recorded 'paid' the moment the transfer succeeds.
     * There is no in_transit → paid lifecycle to await: a Transfer has no settlement
     * webhook (only the connected account's own bank payout does, on Stripe's
     * schedule). The sole caller is security-deposit forfeiture.
     *
     * @param array<string,string> $metadata extra correlation keys for the transfer
     * @throws \InvalidArgumentException when the gross amount is not positive
     * @throws \RuntimeException         when the landowner cannot receive payouts
     */
    public function disburse(User $landowner, int $grossCents, array $metadata = [], ?Carbon $scheduledFor = null): Payout
    {
        if ($grossCents <= 0) {
            throw new \InvalidArgumentException('Gross payout amount must be positive.');
        }

        $account = $this->connectAccount($landowner);
        if ($account === null || ! $account->payouts_enabled) {
            throw new \RuntimeException("Landowner {$landowner->id} has no payouts-enabled Stripe Connect account.");
        }

        $quote = $this->quote($landowner, $grossCents);

        $transfer = $this->stripe->createTransfer(
            $quote['net_cents'],
            $account->stripe_account_id,
            array_merge(['payee_user_id' => $landowner->id], $metadata),
        );

        $payout = Payout::create([
            'payee_user_id'      => $landowner->id,
            'stripe_account_id'  => $account->stripe_account_id,
            'amount_cents'       => $quote['net_cents'],
            'currency'           => 'USD',
            'status'             => 'paid',
            'stripe_transfer_id' => $transfer->id,
            'scheduled_for'      => $scheduledFor,
            'paid_at'            => now(),
        ]);

        $this->audit->log(
            eventType:      'payout.created',
            sourceDatabase: 'ah_billing',
            tableName:      'payouts',
            recordId:       $payout->id,
            userId:         $landowner->id,
            actionSummary:  'Landowner payout transfer completed via Stripe Connect',
            newValues:      [
                'gross_cents' => $quote['gross_cents'],
                'fee_pct'     => $quote['fee_pct'],
                'fee_cents'   => $quote['fee_cents'],
                'net_cents'   => $quote['net_cents'],
                'status'      => 'paid',
            ],
        );

        return $payout;
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    private function feePctFromVersion(string $versionId): ?float
    {
        $version = PlanVersion::on('platform')->find($versionId);
        if (! $version) {
            return null;
        }

        if ($version->platform_fee_pct !== null) {
            return (float) $version->platform_fee_pct;
        }

        $plan = MembershipPlan::on('platform')->find($version->plan_id);

        return $plan && $plan->platform_fee_pct !== null ? (float) $plan->platform_fee_pct : null;
    }

    private function freeTierFeePct(string $accountType): float
    {
        $plan = MembershipPlan::on('platform')
            ->where('plan_key', $this->defaultPlanKey($accountType))
            ->first();

        return $plan && $plan->platform_fee_pct !== null ? (float) $plan->platform_fee_pct : 0.0;
    }

    private function defaultPlanKey(string $accountType): string
    {
        return match ($accountType) {
            'landowner'  => 'landowner_homestead',
            'club'       => 'club_basic',
            'outfitter'  => 'outfitter_standard',
            'consultant' => 'consultant_basic',
            'seller'     => 'seller_standard',
            default      => 'landowner_homestead',
        };
    }

    private function activeSubscription(string $userId): ?Subscription
    {
        return Subscription::on('billing')
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first();
    }

    private function activePromotionClaim(string $userId): ?PromotionClaim
    {
        return PromotionClaim::on('billing')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('activated_at')
            ->first();
    }
}
