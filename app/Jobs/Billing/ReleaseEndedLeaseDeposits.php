<?php

namespace App\Jobs\Billing;

use App\Models\Billing\SecurityDeposit;
use App\Models\Lease\Lease;
use App\Services\Billing\SecurityDepositService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily auto-release of security deposits whose lease has ended (Phase 5.x).
 *
 * Honors the "refundable hold, returned at lease end" promise without an admin
 * having to click Release on every lease. Releases a still-held deposit once its
 * lease ended more than a grace window ago, refunding the full balance to the
 * lessee via SecurityDepositService::release (which runs the Stripe refund, marks
 * the row released, and audits it).
 *
 * Guardrails — only NORMALLY-ended leases auto-release:
 *   • the deposit must still be `held` (the query filters it; release re-checks);
 *   • the lease must be `active` (ran past its end_date) or `expired`;
 *   • `terminated` / `cancelled` leases are skipped — an abnormal ending may carry
 *     a landowner claim, so those stay for an admin to release or forfeit by hand;
 *   • deposits with a pending forfeiture-claim (`forfeit_trust_status = 'pending'`)
 *     are excluded — they settle through the dispute loop, not auto-release.
 * The run first finalizes forfeiture-claims whose contest window has lapsed.
 *
 * Runs on the standard queue, where the worker connects under the trusted
 * ah_system role, so the release writes to the system-authored security_deposits
 * table are permitted.
 */
class ReleaseEndedLeaseDeposits implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;   // a re-runnable sweep — let the daily schedule retry
    public int $timeout = 600;

    /** Lease statuses that represent a normal end and so are safe to auto-release. */
    private const RELEASABLE_LEASE_STATUSES = ['active', 'expired'];

    public function __construct(private readonly int $graceDays = 14)
    {
        $this->onQueue('default');
    }

    public function handle(SecurityDepositService $deposits): void
    {
        if (! config('services.stripe.secret')) {
            Log::info('ReleaseEndedLeaseDeposits: skipped — Stripe not configured');
            return;
        }

        // First, finalize any forfeiture-claims whose contest window lapsed with no
        // open dispute — they uphold as the landowner claimed (the hunter never
        // contested). This runs before the release sweep so a settled forfeiture
        // isn't seen as a still-held deposit to auto-return.
        $autoFinalized = $deposits->autoFinalizePastDeadline();

        $cutoff   = now()->subDays($this->graceDays);
        $released = 0;
        $skipped  = 0;

        SecurityDeposit::where('status', 'held')
            ->whereNull('forfeit_trust_status') // a pending forfeiture-claim is not auto-releasable
            ->chunkById(200, function ($heldDeposits) use ($deposits, $cutoff, &$released, &$skipped) {
                foreach ($heldDeposits as $deposit) {
                    // Cross-DB: the lease lives in DB 3 — a separate read, never a join.
                    $lease = Lease::find($deposit->lease_id);

                    if (! $lease
                        || ! $lease->end_date
                        || $lease->end_date->gt($cutoff)
                        || ! in_array($lease->status, self::RELEASABLE_LEASE_STATUSES, true)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $deposits->release(
                            $deposit->id,
                            null, // actor: the system (no admin) — distinguishes auto-release in the audit
                            "Auto-released: lease ended more than {$this->graceDays} days ago",
                        );
                        $released++;
                    } catch (\Throwable $e) {
                        // One deposit's refund failure must not abort the sweep; the next
                        // daily run retries it. Log our own record id only — never the PI.
                        Log::error('ReleaseEndedLeaseDeposits: release failed', [
                            'security_deposit_id' => $deposit->id,
                            'error'               => $e->getMessage(),
                        ]);
                        $skipped++;
                    }
                }
            });

        Log::info('ReleaseEndedLeaseDeposits: swept ended-lease deposits', [
            'released'       => $released,
            'skipped'        => $skipped,
            'auto_finalized' => $autoFinalized,
            'grace_days'     => $this->graceDays,
        ]);
    }
}
