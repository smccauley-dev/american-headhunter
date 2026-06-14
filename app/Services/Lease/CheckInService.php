<?php

namespace App\Services\Lease;

use App\Models\Lease\CheckIn;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseHunter;
use App\Services\BaseService;
use App\Services\Property\GeospatialService;

/**
 * Field check-in / check-out for active lessees.
 *
 * A check-in QR lives on the property (one physical gate code reused across
 * leases). The scanner is identified by login, then their active lease on that
 * property is looked up here. GPS is advisory only: check_ins stores no
 * coordinates, so a captured point is used solely to warn when the hunter is
 * outside the mapped boundary and to pick the nearest stand.
 */
class CheckInService extends BaseService
{
    public function __construct(private readonly GeospatialService $geo) {}

    /**
     * The user's active lease on this property, whether they are the lessee or an
     * approved hunter on the lease. Null if they have no standing to check in here.
     */
    public function activeLeaseForUserProperty(string $userId, string $propertyId): ?Lease
    {
        $asLessee = Lease::on('lease')
            ->where('property_id', $propertyId)
            ->where('lessee_user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if ($asLessee) {
            return $asLessee;
        }

        $hunterLeaseIds = LeaseHunter::on('lease')
            ->where('user_id', $userId)
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->pluck('lease_id');

        if ($hunterLeaseIds->isEmpty()) {
            return null;
        }

        return Lease::on('lease')
            ->where('property_id', $propertyId)
            ->whereIn('id', $hunterLeaseIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Whether the user may check in against a specific active lease — they are the
     * lessee or an approved hunter on it.
     */
    public function mayCheckIn(string $userId, string $leaseId): bool
    {
        $lease = Lease::on('lease')
            ->where('id', $leaseId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $lease) {
            return false;
        }

        if ($lease->lessee_user_id === $userId) {
            return true;
        }

        return LeaseHunter::on('lease')
            ->where('lease_id', $leaseId)
            ->where('user_id', $userId)
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    public function getOpenForUserLease(string $leaseId, string $userId): ?CheckIn
    {
        return CheckIn::on('lease')
            ->where('lease_id', $leaseId)
            ->where('user_id', $userId)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();
    }

    /**
     * The user's currently-open check-in across any lease, or null. The member
     * dashboard shows this so the hunter always knows they are "in the field".
     */
    public function getOpenForUser(string $userId): ?CheckIn
    {
        return CheckIn::on('lease')
            ->where('user_id', $userId)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();
    }

    /**
     * Record a check-in. Idempotent per (lease, user): if the user already has an
     * open check-in on this lease it is returned unchanged.
     *
     * GPS is advisory. When coordinates are supplied we report whether the point
     * is inside the mapped boundary (warning only — never blocks) and attach the
     * nearest stand so the field log knows where the hunter is sitting.
     *
     * @return array{check_in: CheckIn, within_boundary: ?bool, new: bool}
     */
    public function checkIn(string $userId, string $leaseId, ?float $lat = null, ?float $lng = null): array
    {
        $lease = Lease::on('lease')
            ->where('id', $leaseId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless($this->mayCheckIn($userId, $leaseId), 403);

        $within = $this->within($lease->property_id, $lat, $lng);

        if ($open = $this->getOpenForUserLease($leaseId, $userId)) {
            return ['check_in' => $open, 'within_boundary' => $within, 'new' => false];
        }

        $standId = null;
        if ($lat !== null && $lng !== null) {
            $standId = rescue(
                fn () => $this->geo->getStandsNearPoint($lng, $lat, 300)->first()?->id,
                null,
            );
        }

        $checkIn = CheckIn::create([
            'lease_id'          => $leaseId,
            'user_id'           => $userId,
            'stand_location_id' => $standId,
            'checked_in_at'     => now(),
        ]);

        return ['check_in' => $checkIn, 'within_boundary' => $within, 'new' => true];
    }

    /**
     * Close the user's open check-in on a lease. Returns the closed record, or
     * null if there was nothing open.
     */
    public function checkOut(string $userId, string $leaseId): ?CheckIn
    {
        $open = $this->getOpenForUserLease($leaseId, $userId);

        if (! $open) {
            return null;
        }

        $open->update(['checked_out_at' => now()]);

        return $open;
    }

    /**
     * Check-in / check-out audit history for a property, newest first. Spans every
     * lease the property has ever had. Hunter names are resolved from the identity
     * database (cross-DB assembly happens here, not in the view).
     *
     * @return list<array{name:string,email:string,lease_ref:string,checked_in_at:?\Illuminate\Support\Carbon,checked_out_at:?\Illuminate\Support\Carbon,open:bool}>
     */
    public function getHistoryForProperty(string $propertyId, int $limit = 200): array
    {
        $leaseIds = Lease::on('lease')
            ->where('property_id', $propertyId)
            ->pluck('id');

        if ($leaseIds->isEmpty()) {
            return [];
        }

        $checkIns = CheckIn::on('lease')
            ->whereIn('lease_id', $leaseIds)
            ->orderByDesc('checked_in_at')
            ->limit($limit)
            ->get();

        if ($checkIns->isEmpty()) {
            return [];
        }

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $checkIns->pluck('user_id')->unique()->values())
            ->get()
            ->keyBy('id');

        return $checkIns->map(function (CheckIn $c) use ($users) {
            $user = $users->get($c->user_id);

            return [
                'name'           => $user?->profile?->full_name ?: ($user?->email ?? 'Unknown user'),
                'email'          => $user?->email ?? '',
                'lease_ref'      => strtoupper(substr($c->lease_id, 0, 8)),
                'checked_in_at'  => $c->checked_in_at,
                'checked_out_at' => $c->checked_out_at,
                'open'           => $c->checked_out_at === null,
            ];
        })->all();
    }

    /** Advisory boundary test — null when no coordinates or the check fails. */
    private function within(string $propertyId, ?float $lat, ?float $lng): ?bool
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return rescue(fn () => $this->geo->isPointWithinProperty($propertyId, $lng, $lat), null);
    }
}
