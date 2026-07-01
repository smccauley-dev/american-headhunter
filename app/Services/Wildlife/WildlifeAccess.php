<?php

namespace App\Services\Wildlife;

use App\Models\Lease\Lease;
use App\Services\Lease\CheckInService;
use App\Services\Property\PropertyService;

/**
 * The single authorization boundary for DB 5 (Wildlife).
 *
 * DB 5 has NO row-level security by design — field-operation records are
 * access-controlled at the service layer, not the database. That makes this
 * guard load-bearing: a missing standing check is silent cross-tenant data
 * exposure with no database backstop. Every wildlife read and write routes
 * through here.
 *
 * Standing reuses the check-in logic verbatim (the lessee, or an approved
 * hunter on the lease). Landowner/manager access is scoped to their own
 * properties via PropertyService.
 */
class WildlifeAccess
{
    public function __construct(
        private readonly CheckInService $checkIns,
        private readonly PropertyService $properties,
    ) {}

    /**
     * The active lease the user has standing on, or abort 403. Returns the lease
     * so callers can read its property_id without a second lookup. Used for every
     * lease-scoped write (harvest, sighting, fishing, camera).
     */
    public function assertLeaseStanding(string $userId, string $leaseId): Lease
    {
        abort_unless($this->checkIns->mayCheckIn($userId, $leaseId), 403);

        return Lease::on('lease')
            ->where('id', $leaseId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    /** Standing on a lease without needing the model back (advisory checks / gating UI). */
    public function hasLeaseStanding(string $userId, string $leaseId): bool
    {
        return $this->checkIns->mayCheckIn($userId, $leaseId);
    }

    /**
     * Whether the user may read wildlife data scoped to a property: they either
     * have an active lease on it (hunter) or manage/own the property (landowner).
     */
    public function canAccessProperty(string $userId, string $propertyId): bool
    {
        if ($this->checkIns->activeLeaseForUserProperty($userId, $propertyId) !== null) {
            return true;
        }

        return $this->properties->userCanManageProperty($userId, $propertyId);
    }

    /** Property-scoped read gate, or abort 403 (quota views, cameras, surveys). */
    public function assertPropertyAccess(string $userId, string $propertyId): void
    {
        abort_unless($this->canAccessProperty($userId, $propertyId), 403);
    }

    /**
     * Whether the user may read a specific wildlife record. They own it, still
     * have standing on its lease, or manage the property it was logged on.
     */
    public function canAccessRecord(string $userId, string $recordUserId, string $leaseId, string $propertyId): bool
    {
        return $recordUserId === $userId
            || $this->checkIns->mayCheckIn($userId, $leaseId)
            || $this->properties->userCanManageProperty($userId, $propertyId);
    }

    /** Record-level read gate, or abort 404 (don't disclose existence). */
    public function assertRecordAccess(string $userId, string $recordUserId, string $leaseId, string $propertyId): void
    {
        abort_unless($this->canAccessRecord($userId, $recordUserId, $leaseId, $propertyId), 404);
    }
}
