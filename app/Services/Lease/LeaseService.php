<?php

namespace App\Services\Lease;

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
