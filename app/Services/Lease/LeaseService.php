<?php

namespace App\Services\Lease;

use App\Models\Lease\Club;
use App\Models\Lease\ClubMember;
use App\Models\Lease\Lease;
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
    ) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getLeaseDetail(string $leaseId): LeaseDetailDTO
    {
        return $this->cache("lease_detail:{$leaseId}", fn () => $this->buildLeaseDetail($leaseId), 10);
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

    public function createFromApplication(string $applicationId, array $attributes): Lease
    {
        $lease = Lease::create(array_merge($attributes, [
            'application_id' => $applicationId,
            'status'         => 'pending_signatures',
        ]));

        return $lease;
    }

    public function activate(string $leaseId): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update(['status' => 'active']);
        $this->invalidate("lease_detail:{$leaseId}");
    }

    /**
     * Cancel a lease that never went into effect (no signatures recorded).
     * Use terminate() for leases that were active.
     */
    public function cancel(string $leaseId, string $reason): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update([
            'status'             => 'cancelled',
            'terminated_at'      => now(),
            'termination_reason' => $reason,
        ]);
        $this->invalidate("lease_detail:{$leaseId}");
    }

    public function terminate(string $leaseId, string $reason): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update([
            'status'               => 'terminated',
            'terminated_at'        => now(),
            'termination_reason'   => $reason,
        ]);
        $this->invalidate("lease_detail:{$leaseId}");
    }

    public function expire(string $leaseId): void
    {
        $lease = Lease::findOrFail($leaseId);
        $lease->update(['status' => 'expired']);
        $this->invalidate("lease_detail:{$leaseId}");
    }
}
