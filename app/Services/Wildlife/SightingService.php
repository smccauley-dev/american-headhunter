<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\WildlifeSighting;
use App\Services\BaseService;
use App\Services\Property\GeospatialService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Wildlife sighting logging. Standing-gated with the same offline dedup and
 * GPS-to-DB-13 discipline as harvest logging; no quota or CWD (sightings are
 * observational, not takes).
 */
class SightingService extends BaseService
{
    public function __construct(
        private readonly WildlifeAccess $access,
        private readonly GeospatialService $geo,
    ) {}

    /**
     * @param  array<string,mixed>  $data  species_code, sighting_date (required);
     *                                     sighting_time, count, notes, photo_document_ids[]; latitude, longitude,
     *                                     gps_accuracy_m; local_record_id.
     */
    public function log(string $userId, string $leaseId, array $data): WildlifeSighting
    {
        $lease = $this->access->assertLeaseStanding($userId, $leaseId);

        $localId = $data['local_record_id'] ?? null;
        if ($localId !== null) {
            $existing = WildlifeSighting::on('wildlife')
                ->where('user_id', $userId)
                ->where('local_record_id', $localId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $sightingId = (string) Str::uuid();

        try {
            $geoId = ($lat !== null && $lng !== null)
                ? $this->geo->storeHarvestLocation($sightingId, $lng, $lat, $data['gps_accuracy_m'] ?? null)
                : null;

            return WildlifeSighting::create([
                'id' => $sightingId,
                'lease_id' => $leaseId,
                'user_id' => $userId,
                'property_id' => $lease->property_id,
                'species_code' => $data['species_code'],
                'sighting_date' => $data['sighting_date'],
                'sighting_time' => $data['sighting_time'] ?? null,
                'count' => $data['count'] ?? 1,
                'location_geospatial_id' => $geoId,
                'notes' => $data['notes'] ?? null,
                'photo_document_ids' => $data['photo_document_ids'] ?? [],
                'local_record_id' => $localId,
            ]);
        } catch (\Throwable $e) {
            if ($localId !== null) {
                $winner = WildlifeSighting::on('wildlife')
                    ->where('user_id', $userId)
                    ->where('local_record_id', $localId)
                    ->first();

                if ($winner) {
                    return $winner;
                }
            }

            throw $e;
        }
    }

    /** @return Collection<int,WildlifeSighting> */
    public function listForUser(string $userId, int $limit = 100): Collection
    {
        return WildlifeSighting::on('wildlife')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('sighting_date')
            ->limit($limit)
            ->get();
    }
}
