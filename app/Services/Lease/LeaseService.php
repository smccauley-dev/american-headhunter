<?php

namespace App\Services\Lease;

use App\Models\Lease\Club;
use App\Models\Lease\ClubMember;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
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
        $property = $this->propertyService->findById($lease->property_id);
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
     * Lease summaries for a lessee's portal — active and awaiting-signature
     * leases, each assembled with its property (DB 2) via the service layer.
     * Shared by the member dashboard overview and the profile "My Leases" tab.
     * Not cached: a freshly signed lease must appear immediately.
     */
    public function getLeaseSummariesForLessee(string $userId): array
    {
        $leases = Lease::whereIn('status', ['active', 'pending_signatures'])
            ->where('lessee_user_id', $userId)
            ->whereNull('deleted_at')
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

    public function activate(string $leaseId, ?string $actorUserId = null): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update(['status' => 'active']);

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
    }
}
