<?php

namespace App\Services\Lease;

use App\Models\Lease\Club;
use App\Models\Lease\ClubMember;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseTerminationRequest;
use App\Jobs\Lease\SendLeaseTerminationDecisionEmail;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Communications\NotificationService;
use App\Services\Property\PropertyService;
use App\Services\Identity\UserService;
use App\DTOs\LeaseDetailDTO;
use Illuminate\Support\Collection;

class LeaseService extends BaseService
{
    public function __construct(
        private readonly PropertyService $propertyService,
        private readonly UserService     $userService,
        private readonly AuditService    $auditService,
    ) {}

    /**
     * How much PREPAID RENT a hunter forfeits vs gets back when a landowner
     * terminates their lease for a violation. The security deposit is always
     * forfeited separately (a contestable claim) — this governs the rent only.
     */
    public const RENT_FORFEIT      = 'full_forfeit'; // hunter forfeits all prepaid rent
    public const RENT_PRORATED     = 'prorated';     // refund the unused (future) portion of the term
    public const RENT_FULL_REFUND  = 'full_refund';  // refund all prepaid rent (deposit still forfeited)
    public const RENT_CUSTOM       = 'custom';       // landowner-entered amount (approval-time only; requires a note)

    /** Selectable rent policies (the grandfathered/default set). RENT_CUSTOM is approval-time only. */
    public const RENT_POLICIES = [self::RENT_FORFEIT, self::RENT_PRORATED, self::RENT_FULL_REFUND];

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        return $this->cache("lease_detail:{$leaseId}", fn () => $this->buildLeaseDetail($leaseId), 10);
    }

    public function find(string $leaseId): ?Lease
    {
        return Lease::find($leaseId);
    }

    private function buildLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        $lease    = Lease::with(['hunters', 'notes', 'checkIns', 'renewals'])->findOrFail($leaseId);
        $property = $this->propertyService->find($lease->property_id);
        $lessee   = $this->userService->findById($lease->lessee_user_id);
        $lessor   = $this->userService->findById($lease->lessor_user_id);

        return new LeaseDetailDTO(
            lease:    $lease,
            property: $property,
            lessee:   $lessee,
            lessor:   $lessor,
        );
    }

    public function getActiveLeasesForLessee(string $userId): Collection
    {
        return $this->cache("lease:lessee:{$userId}:active", function () use ($userId) {
            return Lease::scopeActive()->where('lessee_user_id', $userId)->get();
        }, 5);
    }

    public function getActiveLeasesForLessor(string $userId): Collection
    {
        return $this->cache("lease:lessor:{$userId}:active", function () use ($userId) {
            return Lease::scopeActive()->where('lessor_user_id', $userId)->get();
        }, 5);
    }

    /**
     * Lease summaries for a lessee's portal — every lease the user holds,
     * including terminated/expired/cancelled ones kept for historical lookup,
     * each assembled with its property (DB 2) via the service layer. Current
     * leases sort ahead of historical ones. Shared by the member dashboard
     * overview and the profile "My Leases" tab.
     * Not cached: a freshly signed lease must appear immediately.
     */
    public function getLeaseSummariesForLessee(string $userId): array
    {
        // Every lease the user holds — including terminated/expired/cancelled ones,
        // which stay visible for historical lookup. Current leases (active /
        // awaiting signature / awaiting payment) sort above historical ones so the
        // first page always shows what's live; newest-first within each group.
        $leases = Lease::where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN status IN ('active', 'pending_signatures', 'pending_payment') THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->get();

        // Resolved lazily — EsignatureService depends on LeaseService, so it
        // cannot be a constructor dependency without creating a cycle.
        $esig = app(EsignatureService::class);

        return $leases->map(function (Lease $lease) use ($userId, $esig) {
            $property = rescue(fn () => $this->propertyService->find($lease->property_id), null);
            $endDate  = $lease->end_date;

            // A lease stays 'pending_signatures' until both parties sign, so the
            // status alone can't tell the lessee whether THEY still owe a
            // signature. Surface that explicitly so the portal stops showing
            // "Sign Now" to someone who has already signed.
            $needsMySignature = false;
            if ($lease->status === 'pending_signatures') {
                $needsMySignature = rescue(function () use ($esig, $lease, $userId) {
                    $request = $esig->getRequestForLease($lease->id);
                    if ($request === null) {
                        return false;
                    }
                    $signer = $esig->signerForUser($request->id, $userId);

                    return $signer !== null && $signer->status !== 'signed';
                }, false);
            }

            return [
                'id'                 => $lease->id,
                'status'             => $lease->status,
                'needs_my_signature' => $needsMySignature,
                'needs_payment'      => $lease->status === 'pending_payment',
                'start_date'        => $lease->start_date?->format('M j, Y'),
                'end_date'          => $endDate?->format('M j, Y'),
                'total_price'       => number_format((float) $lease->total_price, 2),
                'days_until_expiry' => $endDate
                    ? ($endDate->isPast() ? 0 : (int) $endDate->diffInDays(now()))
                    : null,
                'property' => $property ? [
                    'id'     => $property->id,
                    'title'  => $property->title,
                    'county' => $property->county,
                    'state'  => $property->state_code,
                    'acres'  => $property->huntable_acres ?? $property->total_acres,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Whether the user is party to an active lease on the property (as lessee or
     * lessor). Gate for member-only property data such as map markers and access
     * info — markers carry precise on-property GPS (see SEC-024), so this must
     * never be relaxed to a generic "authenticated hunter" check.
     */
    public function userHasActiveLeaseForProperty(string $userId, string $propertyId): bool
    {
        return Lease::on('lease')
            ->where('property_id', $propertyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('lessee_user_id', $userId)
                ->orWhere('lessor_user_id', $userId))
            ->exists();
    }

    /**
     * All leases where the user is lessee or lessor — plain arrays for the
     * admin user detail page. Cached 5 min.
     */
    public function getLeaseSummariesForUser(string $userId): array
    {
        return $this->cache("lease:user:{$userId}:summaries", function () use ($userId) {
            return Lease::on('lease')
                ->where(fn ($q) => $q
                    ->where('lessee_user_id', $userId)
                    ->orWhere('lessor_user_id', $userId))
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->get(['id', 'lessee_user_id', 'lessor_user_id', 'status', 'start_date', 'end_date'])
                ->map(fn ($l) => [
                    'id'         => $l->id,
                    'role'       => $l->lessee_user_id === $userId ? 'Lessee' : 'Lessor',
                    'status'     => $l->status,
                    'start_date' => $l->start_date?->format('M j Y'),
                    'end_date'   => $l->end_date?->format('M j Y'),
                ])->all();
        }, 5);
    }

    /**
     * Clubs the user owns plus clubs they belong to — plain arrays for the
     * admin user detail page. Cached 5 min.
     */
    public function getClubAffiliationsForUser(string $userId): array
    {
        return $this->cache("lease:user:{$userId}:clubs", function () use ($userId) {
            $owned = Club::on('lease')
                ->where('owner_user_id', $userId)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'status'])
                ->map(fn ($c) => ['name' => $c->name, 'role' => 'Owner', 'status' => $c->status]);

            $memberships = ClubMember::on('lease')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->with('club')
                ->get()
                ->map(fn ($m) => [
                    'name'   => $m->club?->name ?? '—',
                    'role'   => ucfirst($m->role),
                    'status' => $m->status,
                ]);

            return $owned->concat($memberships)->values()->all();
        }, 5);
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    public function createFromApplication(string $applicationId, array $attributes, ?string $actorUserId = null): Lease
    {
        $lease = Lease::create(array_merge($attributes, [
            'application_id' => $applicationId,
            'status'         => 'pending_signatures',
        ]));

        $this->auditService->log(
            eventType:      'lease.created',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $lease->id,
            userId:         $actorUserId,
            actionSummary:  'Lease created from approved application (pending signatures)',
            newValues:      ['application_id' => $applicationId, 'status' => 'pending_signatures'],
        );

        return $lease;
    }

    /**
     * Entry point when all signatures are collected. A signed lease is legally
     * executed, but "usable in the field" is a separate state: if a balance is
     * still owed the lease is held in `pending_payment` (field access — check-in,
     * gate QR, stand map — gates on `active`, so it stays locked) and the
     * lease-payment webhook activates it once the balance reaches zero. When
     * nothing is owed it activates immediately. If the balance can't be computed
     * we activate rather than strand a signed lease over a billing read.
     */
    public function finalizeSignatures(string $leaseId, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);

        $balanceDue = rescue(
            fn () => app(\App\Services\Billing\LeasePaymentService::class)->balanceDueCents($lease),
            0,
        );

        if ($balanceDue > 0) {
            $this->markPendingPayment($leaseId, $actorUserId);
            return;
        }

        $this->activate($leaseId, $actorUserId);
    }

    /**
     * Hold a fully-signed lease awaiting its balance. No field side effects (QR,
     * calendar booking, listing promotion) run here — those fire in activate()
     * once the balance is paid.
     */
    private function markPendingPayment(string $leaseId, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update(['status' => 'pending_payment']);

        // Same RLS no-op guard as activate(): fail loudly rather than silently
        // leaving the lease pending_signatures under a role without UPDATE rights.
        if ($lease->fresh()?->status !== 'pending_payment') {
            throw new \RuntimeException(
                "Lease {$leaseId} could not be set pending_payment — the connection role lacks UPDATE on leases."
            );
        }

        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.pending_payment',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  'Lease fully signed; awaiting balance payment before activation',
        );
    }

    public function activate(string $leaseId, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        // Clear the 7-day completion clock — the lease completed in time, so the
        // deadline-enforcement command must not forfeit its booking fee.
        $lease->update(['status' => 'active', 'completion_deadline' => null]);

        // Guard against a silent RLS no-op: under a role without UPDATE rights on
        // `leases` the write affects zero rows without raising, which previously
        // let activation report success while the lease stayed pending. Re-read
        // and fail loudly so a misconfigured role can never strand a lease.
        if ($lease->fresh()?->status !== 'active') {
            throw new \RuntimeException(
                "Lease {$leaseId} activation did not persist — the connection role lacks UPDATE on leases."
            );
        }

        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.activated',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  'Lease activated',
        );

        // Lease is now executed — ensure the property has a check-in QR (used at
        // the gate). Never let QR setup break activation.
        rescue(fn () => app(\App\Services\Documents\DocumentService::class)
            ->getOrCreateCheckInQrForProperty($lease->property_id));

        // Day-hunt leases reserve their dates on the property calendar so the
        // range shows as booked and can't be re-sold. No-op for other listing
        // types. Best-effort: a calendar write (including an overlap conflict
        // from the EXCLUDE constraint) must never strand an already-executed
        // lease, mirroring the QR rescue above.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->markBooked(
            listingId:       $lease->listing_id,
            start:           $lease->start_date,
            end:             $lease->end_date,
            hunters:         $lease->hunters()->count(),
            cost:            (float) $lease->total_price,
            leaseId:         $lease->id,
            createdByUserId: $actorUserId,
        ));

        // An exclusive listing was set `pending` at approval; now that the lease
        // is executed, promote it to `leased`. No-op for day-hunt listings.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)
            ->markExclusiveLeased($lease->listing_id));

        // The vet-first booking fee was held on the platform pending this outcome;
        // the lease completed, so release it to the landowner. No-op when there is
        // no held fee. Best-effort — never let disbursement break activation.
        rescue(fn () => app(\App\Services\Billing\BookingDepositService::class)
            ->disburseForLease($lease->id));
    }

    /**
     * Cancel a lease that never went into effect (no signatures recorded).
     * Use terminate() for leases that were active.
     */
    public function cancel(string $leaseId, string $reason, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update([
            'status'             => 'cancelled',
            'terminated_at'      => now(),
            'termination_reason' => $reason,
        ]);
        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.cancelled',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  "Lease cancelled: {$reason}",
        );

        // Free any day-hunt dates this lease held so they become bookable again.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->releaseBooking($leaseId));

        // Close any open check-in so a forgotten hunter is no longer "in the field".
        rescue(fn () => app(CheckInService::class)->closeOpenForLease($leaseId));
    }

    public function terminate(string $leaseId, string $reason, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update([
            'status'               => 'terminated',
            'terminated_at'        => now(),
            'termination_reason'   => $reason,
        ]);
        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.terminated',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  "Lease terminated: {$reason}",
        );

        // Free any day-hunt dates this lease held so they become bookable again.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->releaseBooking($leaseId));

        // Close any open check-in so a forgotten hunter is no longer "in the field".
        rescue(fn () => app(CheckInService::class)->closeOpenForLease($leaseId));
    }

    /**
     * Terminate an active lease for the hunter's violation of the agreement. Two
     * money consequences, both best-effort so a billing hiccup never strands the
     * termination itself:
     *
     *  1. The security deposit is forfeited as a contestable FAULT_LESSEE claim —
     *     the money stays held and the hunter can dispute it (a provisional Trust
     *     hit), exactly like a damage forfeiture.
     *  2. Prepaid rent is forfeited or refunded per the lease's snapshotted
     *     early-termination policy ($rentDisposition overrides it for this action):
     *       full_forfeit → none refunded · prorated → unused portion · full_refund → all.
     *     The booking fee (a non-refundable commitment) is never touched.
     *
     * @throws \RuntimeException         when the lease is not active
     * @throws \InvalidArgumentException when $rentDisposition is not a known policy
     */
    public function terminateForViolation(
        string $leaseId,
        string $reason,
        ?string $rentDisposition = null,
        ?string $actorUserId = null,
    ): void {
        $lease = Lease::findOrFail($leaseId);

        if ($lease->status !== 'active') {
            throw new \RuntimeException("Only an active lease can be terminated for a violation (status {$lease->status}).");
        }

        $disposition = $rentDisposition ?? $lease->early_termination_rent_policy ?? self::RENT_FORFEIT;
        if (! in_array($disposition, self::RENT_POLICIES, true)) {
            throw new \InvalidArgumentException("Invalid rent disposition: {$disposition}.");
        }

        $lease->update([
            'status'             => 'terminated',
            'terminated_at'      => now(),
            'termination_reason' => $reason,
        ]);
        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.terminated_for_violation',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  "Lease terminated for violation ({$disposition} prepaid rent): {$reason}",
            newValues:      ['status' => 'terminated', 'rent_disposition' => $disposition],
        );

        // Forfeit the deposit as a contestable hunter-fault claim (money stays held).
        rescue(fn () => $this->forfeitDepositForViolation($leaseId, $reason, $actorUserId));

        // Refund prepaid rent per the policy (reverses the destination charge + fee).
        rescue(fn () => $this->refundPrepaidRent($lease, $disposition, $actorUserId));

        // Free the reserved term so the listing returns to the market.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->releaseBooking($leaseId));

        // Close any open check-in so a forgotten hunter is no longer "in the field".
        rescue(fn () => app(CheckInService::class)->closeOpenForLease($leaseId));
    }

    /**
     * File the deposit forfeiture for a violation termination: the full remaining
     * held balance, as a contestable FAULT_LESSEE claim. No-op when there is no
     * held deposit or one is already claimed.
     */
    private function forfeitDepositForViolation(string $leaseId, string $reason, ?string $actorUserId): void
    {
        $deposits = app(\App\Services\Billing\SecurityDepositService::class);
        $deposit  = $deposits->forLease($leaseId);
        if (! $deposit || $deposit->status !== 'held' || $deposit->forfeit_fault !== null) {
            return;
        }

        $remaining = $deposit->remainingCents();
        if ($remaining <= 0) {
            return;
        }

        $deposits->forfeit(
            $deposit->id,
            $remaining,
            $reason,
            $actorUserId,
            \App\Services\Billing\SecurityDepositService::FAULT_LESSEE,
            'rule_violation',
        );
    }

    /**
     * Refund prepaid rent (collected lease payments) per the chosen disposition.
     * full_forfeit refunds nothing; full_refund the whole collected gross; prorated
     * the unused (future) portion of the term; custom a landowner-entered amount
     * (capped at the collected gross). Refunds are applied charge-by-charge (each
     * reverses its destination transfer + platform fee) until the budget is spent.
     * Only untouched 'collected' charges are refunded — partials are left be.
     */
    private function refundPrepaidRent(Lease $lease, string $disposition, ?string $actorUserId, ?int $customCents = null): int
    {
        if ($disposition === self::RENT_FORFEIT) {
            return 0;
        }

        $payments   = app(\App\Services\Billing\LeasePaymentService::class);
        $refundable = $payments->collectedFor($lease->id)->where('status', 'collected');
        $totalGross = (int) $refundable->sum('gross_cents');
        if ($totalGross <= 0) {
            return 0;
        }

        $budget = match ($disposition) {
            self::RENT_FULL_REFUND => $totalGross,
            self::RENT_CUSTOM      => min(max(0, (int) $customCents), $totalGross),
            default                => $this->unusedRentCents($lease, $totalGross), // prorated
        };
        if ($budget <= 0) {
            return 0;
        }

        $refunded = 0;
        foreach ($refundable as $payment) {
            if ($budget <= 0) {
                break;
            }
            $gross  = (int) $payment->gross_cents;
            $amount = min($budget, $gross);
            $payments->refund($payment, $amount >= $gross ? null : $amount, $actorUserId);
            $budget   -= $amount;
            $refunded += $amount;
        }

        return $refunded;
    }

    /**
     * The portion of prepaid rent attributable to the UNUSED (future) part of the
     * term — straight-line by day. Whole amount before the term starts, nothing once
     * it has ended.
     */
    private function unusedRentCents(Lease $lease, int $totalGross): int
    {
        $start = $lease->start_date;
        $end   = $lease->end_date;
        if (! $start || ! $end || ! $end->greaterThan($start)) {
            return 0;
        }

        $now = now();
        if ($now->lessThanOrEqualTo($start)) {
            return $totalGross;
        }
        if ($now->greaterThanOrEqualTo($end)) {
            return 0;
        }

        // Day-granular: a partial current day counts as not-yet-used (refundable).
        $totalDays = $start->diffInDays($end);
        $usedDays  = floor($start->diffInDays($now));

        return (int) round($totalGross * (($totalDays - $usedDays) / $totalDays));
    }

    // ── Hunter-requested early termination ──────────────────────────────────────

    /**
     * A hunter asks to end their active lease early. Records a pending request the
     * landowner must approve or deny — nothing terminates and no money moves yet.
     * Only the lessee may ask, only on an active lease, and only one open request
     * may exist at a time.
     *
     * @throws \RuntimeException when the lease is not active, the user is not the
     *                           lessee, or an open request already exists
     */
    public function requestEarlyTermination(string $leaseId, string $reason, string $requesterUserId): LeaseTerminationRequest
    {
        $lease = Lease::findOrFail($leaseId);

        if ($lease->status !== 'active') {
            throw new \RuntimeException("Only an active lease can be ended early (status {$lease->status}).");
        }
        if ($lease->lessee_user_id !== $requesterUserId) {
            throw new \RuntimeException('Only the hunter on the lease may request early termination.');
        }
        if ($this->openTerminationRequest($leaseId) !== null) {
            throw new \RuntimeException('There is already a pending early-termination request for this lease.');
        }

        $request = LeaseTerminationRequest::create([
            'lease_id'             => $leaseId,
            'requested_by_user_id' => $requesterUserId,
            'reason'               => $reason,
            'status'               => 'pending',
        ]);
        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.early_termination_requested',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_termination_requests',
            recordId:       $request->id,
            userId:         $requesterUserId,
            actionSummary:  "Hunter requested early termination: {$reason}",
        );

        return $request;
    }

    /**
     * The landowner approves a hunter's early-termination request: the lease is
     * terminated and the hunter forfeits the security deposit as a non-contestable
     * early-exit penalty (settled immediately, kept by the landowner, no Trust hit).
     *
     * The landowner may soften that penalty by returning some of the deposit:
     * $depositRefundCents (0..held remaining) is refunded to the hunter and the rest
     * kept. Null or 0 keeps the whole deposit (the default); a value equal to the
     * held balance refunds it in full (no penalty). Prepaid rent is left as-is — the
     * configurable rent policy applies only to a violation termination. Only the
     * lessor may approve, only while the request is pending and the lease still
     * active.
     *
     * @throws \RuntimeException         when the user is not the lessor, the request is
     *                                   not pending, or the lease is no longer active
     * @throws \InvalidArgumentException when the refund exceeds the held deposit
     */
    public function approveEarlyTermination(string $requestId, ?string $note, string $deciderUserId, ?int $depositRefundCents = null, ?string $rentDisposition = null, ?int $rentRefundCents = null): void
    {
        $request = LeaseTerminationRequest::findOrFail($requestId);
        $lease   = $this->guardDecision($request, $deciderUserId);

        $refundCents = max(0, $depositRefundCents ?? 0);
        if ($refundCents > 0 && $refundCents > $this->heldDepositRemainingCents($lease->id)) {
            throw new \InvalidArgumentException('The deposit refund cannot exceed the held deposit.');
        }

        // Prepaid rent is a second, separate pot from the deposit penalty. The
        // landowner chooses how much to return (defaults to the lease's snapshotted
        // policy), settled by the same engine the violation path uses. 'custom' is a
        // free-form amount the landowner enters — it must be accompanied by a note.
        $disposition = $rentDisposition ?? $lease->early_termination_rent_policy ?? self::RENT_FORFEIT;
        if (! in_array($disposition, [...self::RENT_POLICIES, self::RENT_CUSTOM], true)) {
            throw new \InvalidArgumentException("Invalid rent disposition: {$disposition}.");
        }
        if ($disposition === self::RENT_CUSTOM && trim((string) $note) === '') {
            throw new \InvalidArgumentException('A note is required when refunding a custom rent amount.');
        }

        $request->update([
            'status'             => 'approved',
            'decided_by_user_id' => $deciderUserId,
            'decision_note'      => $note,
            'decided_at'         => now(),
        ]);

        $lease->update([
            'status'             => 'terminated',
            'terminated_at'      => now(),
            'termination_reason' => 'Early termination requested by hunter — approved by landowner',
        ]);
        $this->invalidate("lease_detail:{$lease->id}");

        $this->auditService->log(
            eventType:      'lease.early_termination_approved',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_termination_requests',
            recordId:       $requestId,
            userId:         $deciderUserId,
            actionSummary:  "Hunter early-termination request approved; lease terminated ({$disposition} prepaid rent)",
            newValues:      ['lease_id' => $lease->id, 'status' => 'approved', 'rent_disposition' => $disposition],
        );

        // Hunter forfeits the deposit as a non-contestable early-exit penalty: an
        // immediate keep-for-landowner settlement with no Trust hit. The landowner
        // may return part or all of it ($refundCents) as goodwill.
        rescue(fn () => $this->forfeitDepositForEarlyExit($lease->id, $deciderUserId, $refundCents));

        // Refund prepaid rent per the landowner's chosen disposition (reverses the
        // destination charge + platform fee), exactly as the violation path does.
        $rentRefundedCents = (int) rescue(fn () => $this->refundPrepaidRent($lease, $disposition, $deciderUserId, $rentRefundCents), 0);

        // Snapshot what the hunter is owed so their lease page can show the outcome
        // after the fact (lease_payments tracks only a refund status, not amounts).
        $request->update([
            'deposit_refunded_cents' => $refundCents,
            'rent_refunded_cents'    => $rentRefundedCents,
        ]);

        // Free the reserved term so the listing returns to the market.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->releaseBooking($lease->id));

        // Close any open check-in so a forgotten hunter is no longer "in the field".
        rescue(fn () => app(CheckInService::class)->closeOpenForLease($lease->id));

        // Tell the hunter (email) their request was approved and what was refunded.
        rescue(fn () => SendLeaseTerminationDecisionEmail::dispatch(
            $lease->id, $lease->lessee_user_id, 'approved', $refundCents, $rentRefundedCents, $note,
        ));

        // In-app bell. Exact figures live on the lease page banner — keep the body
        // high-level (no amounts) and link there. System-authored: this runs under
        // db.system (ah_system), so the insert is permitted.
        rescue(fn () => app(NotificationService::class)->notify(
            userId:    $lease->lessee_user_id,
            type:      'lease.early_termination_approved',
            title:     'Early termination approved',
            body:      'Your request to end your lease early was approved — the lease is now terminated. Open your lease to see any refund details.',
            actionUrl: "/member/leases/{$lease->id}",
            data:      ['lease_id' => $lease->id],
        ));
    }

    /**
     * The landowner denies a hunter's early-termination request. The lease is
     * untouched and the hunter remains bound by it.
     *
     * @throws \RuntimeException when the user is not the lessor or the request is
     *                           not pending
     */
    public function denyEarlyTermination(string $requestId, ?string $note, string $deciderUserId): void
    {
        $request = LeaseTerminationRequest::findOrFail($requestId);
        $lease   = $this->guardDecision($request, $deciderUserId);

        $request->update([
            'status'             => 'denied',
            'decided_by_user_id' => $deciderUserId,
            'decision_note'      => $note,
            'decided_at'         => now(),
        ]);
        $this->invalidate("lease_detail:{$lease->id}");

        $this->auditService->log(
            eventType:      'lease.early_termination_denied',
            sourceDatabase: 'ah_lease',
            tableName:      'lease_termination_requests',
            recordId:       $requestId,
            userId:         $deciderUserId,
            actionSummary:  'Hunter early-termination request denied',
            newValues:      ['lease_id' => $lease->id, 'status' => 'denied'],
        );

        // Tell the hunter (email) their request was denied and the lease stands.
        rescue(fn () => SendLeaseTerminationDecisionEmail::dispatch(
            $lease->id, $lease->lessee_user_id, 'denied', 0, 0, $note,
        ));

        // In-app bell — system-authored (runs under db.system / ah_system).
        rescue(fn () => app(NotificationService::class)->notify(
            userId:    $lease->lessee_user_id,
            type:      'lease.early_termination_denied',
            title:     'Early termination not approved',
            body:      'Your request to end your lease early was not approved — the lease remains active. You can submit a new request from your lease page.',
            actionUrl: "/member/leases/{$lease->id}",
            data:      ['lease_id' => $lease->id],
        ));
    }

    /** The open (pending) early-termination request for a lease, if any. */
    public function openTerminationRequest(string $leaseId): ?LeaseTerminationRequest
    {
        return LeaseTerminationRequest::where('lease_id', $leaseId)
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();
    }

    /**
     * Shared guards for a landowner decision: the request must be pending, the
     * actor must be the lease's lessor, and the lease must still be active.
     * Returns the lease for the caller to act on.
     */
    private function guardDecision(LeaseTerminationRequest $request, string $deciderUserId): Lease
    {
        if ($request->status !== 'pending') {
            throw new \RuntimeException("This request has already been {$request->status}.");
        }

        $lease = Lease::findOrFail($request->lease_id);
        if ($lease->lessor_user_id !== $deciderUserId) {
            throw new \RuntimeException('Only the landowner on the lease may decide this request.');
        }
        if ($lease->status !== 'active') {
            throw new \RuntimeException("The lease is no longer active (status {$lease->status}).");
        }

        return $lease;
    }

    /** The remaining balance of the lease's held, unclaimed deposit (0 when none). */
    private function heldDepositRemainingCents(string $leaseId): int
    {
        $deposit = app(\App\Services\Billing\SecurityDepositService::class)->forLease($leaseId);
        if (! $deposit || $deposit->status !== 'held' || $deposit->forfeit_fault !== null) {
            return 0;
        }

        return $deposit->remainingCents();
    }

    /**
     * Settle the held deposit on an approved early exit. The hunter forfeits it as a
     * non-contestable early-exit penalty (FAULT_LANDOWNER_INITIATED — immediate
     * keep-for-landowner, no Trust hit), less any goodwill refund the landowner chose:
     *
     *  - $refundCents <= 0          → keep the whole deposit (the default).
     *  - 0 < $refundCents < held    → keep the difference, refund the rest (a partial
     *                                 forfeit settles 'keep' and auto-returns the
     *                                 remainder to the hunter).
     *  - $refundCents >= held       → refund the deposit in full, no penalty (release).
     *
     * No-op when nothing is held or a claim already exists.
     */
    private function forfeitDepositForEarlyExit(string $leaseId, ?string $actorUserId, int $refundCents = 0): void
    {
        $deposits = app(\App\Services\Billing\SecurityDepositService::class);
        $deposit  = $deposits->forLease($leaseId);
        if (! $deposit || $deposit->status !== 'held' || $deposit->forfeit_fault !== null) {
            return;
        }

        $remaining = $deposit->remainingCents();
        if ($remaining <= 0) {
            return;
        }

        $refundCents = min(max(0, $refundCents), $remaining);

        // Full goodwill refund: return everything, no forfeiture.
        if ($refundCents >= $remaining) {
            $deposits->release($deposit->id, $actorUserId, 'Early termination approved — deposit refunded to hunter');

            return;
        }

        // Keep the rest as the early-exit penalty; a partial forfeit settles 'keep'
        // and refunds any remainder ($refundCents) to the hunter automatically.
        $deposits->forfeit(
            $deposit->id,
            $remaining - $refundCents,
            'Early termination requested by hunter — deposit forfeited as early-exit penalty',
            $actorUserId,
            \App\Services\Billing\SecurityDepositService::FAULT_LANDOWNER_INITIATED,
            'other',
        );
    }

    public function expire(string $leaseId, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update(['status' => 'expired']);
        $this->invalidate("lease_detail:{$leaseId}");

        $this->auditService->log(
            eventType:      'lease.expired',
            sourceDatabase: 'ah_lease',
            tableName:      'leases',
            recordId:       $leaseId,
            userId:         $actorUserId,
            actionSummary:  'Lease expired',
        );

        // Free any reserved dates so an exclusive listing returns to the market
        // and a day-hunt's dates become bookable again.
        rescue(fn () => app(\App\Services\Property\PropertyService::class)->releaseBooking($leaseId));

        // Close any open check-in so a forgotten hunter is no longer "in the field".
        rescue(fn () => app(CheckInService::class)->closeOpenForLease($leaseId));
    }
}
