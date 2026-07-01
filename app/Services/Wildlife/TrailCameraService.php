<?php

namespace App\Services\Wildlife;

use App\Models\Identity\User;
use App\Models\Wildlife\TrailCamera;
use App\Models\Wildlife\TrailCameraPhoto;
use App\Services\BaseService;
use App\Services\Platform\EntitlementService;
use App\Support\Entitlements;
use Illuminate\Support\Collection;

/**
 * Trail camera registration and photo listing.
 *
 * Gated twice: the standing check (DB 5 has no RLS) AND the
 * `trail_camera_integration` entitlement — the feature is not on every plan.
 * Vendor-feed sync and AI species tagging are Phase 6.4 jobs; this service is the
 * registration + read surface.
 */
class TrailCameraService extends BaseService
{
    public function __construct(
        private readonly WildlifeAccess $access,
        private readonly EntitlementService $entitlements,
    ) {}

    private function assertEntitled(User $user): void
    {
        abort_unless(
            $this->entitlements->can($user, Entitlements::TRAIL_CAMERA_INTEGRATION),
            403,
            'Trail camera integration is not included in your plan.'
        );
    }

    /**
     * Register a camera against a lease the user has standing on.
     *
     * @param  array<string,mixed>  $data  name (required); model,
     *                                     location_geospatial_id, status.
     */
    public function register(User $user, string $leaseId, array $data): TrailCamera
    {
        $this->assertEntitled($user);
        $lease = $this->access->assertLeaseStanding($user->id, $leaseId);

        return TrailCamera::create([
            'lease_id' => $leaseId,
            'property_id' => $lease->property_id,
            'user_id' => $user->id,
            'name' => $data['name'],
            'model' => $data['model'] ?? null,
            'location_geospatial_id' => $data['location_geospatial_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    /**
     * Cameras on a property the user may access (lessee/hunter or manager).
     *
     * @return Collection<int,TrailCamera>
     */
    public function listForProperty(User $user, string $propertyId): Collection
    {
        $this->assertEntitled($user);
        $this->access->assertPropertyAccess($user->id, $propertyId);

        return TrailCamera::on('wildlife')
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * Photos for a camera the user may access. 404 when the camera is missing so
     * existence is not disclosed.
     *
     * @return Collection<int,TrailCameraPhoto>
     */
    public function photosFor(User $user, string $cameraId, int $limit = 100): Collection
    {
        $this->assertEntitled($user);

        $camera = TrailCamera::on('wildlife')
            ->where('id', $cameraId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->access->assertRecordAccess($user->id, $camera->user_id, $camera->lease_id, $camera->property_id);

        return TrailCameraPhoto::on('wildlife')
            ->where('camera_id', $cameraId)
            ->whereNull('deleted_at')
            ->orderByDesc('taken_at')
            ->limit($limit)
            ->get();
    }
}
