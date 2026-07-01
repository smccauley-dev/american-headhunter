<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\FishingHarvestLog;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\GeospatialService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Fishing catch logging. Same standing + offline-dedup + GPS-to-DB-13 discipline
 * as HarvestService, minus quota and CWD (those are hunting concerns).
 */
class FishingHarvestService extends BaseService
{
    public function __construct(
        private readonly WildlifeAccess $access,
        private readonly GeospatialService $geo,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $data  species_code, catch_date (required);
     *                                     catch_time, length_inches, weight_lbs, catch_and_release, notes, is_public,
     *                                     field_photos[]; latitude, longitude, gps_accuracy_m; local_record_id.
     */
    public function log(string $userId, string $leaseId, array $data): FishingHarvestLog
    {
        $lease = $this->access->assertLeaseStanding($userId, $leaseId);

        $localId = $data['local_record_id'] ?? null;
        if ($localId !== null) {
            $existing = FishingHarvestLog::on('wildlife')
                ->where('user_id', $userId)
                ->where('local_record_id', $localId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $catchId = (string) Str::uuid();

        try {
            $geoId = ($lat !== null && $lng !== null)
                ? $this->geo->storeHarvestLocation($catchId, $lng, $lat, $data['gps_accuracy_m'] ?? null)
                : null;

            $catch = FishingHarvestLog::create([
                'id' => $catchId,
                'lease_id' => $leaseId,
                'user_id' => $userId,
                'property_id' => $lease->property_id,
                'species_code' => $data['species_code'],
                'catch_date' => $data['catch_date'],
                'catch_time' => $data['catch_time'] ?? null,
                'location_geospatial_id' => $geoId,
                'length_inches' => $data['length_inches'] ?? null,
                'weight_lbs' => $data['weight_lbs'] ?? null,
                'catch_and_release' => $data['catch_and_release'] ?? false,
                'field_photos' => $data['field_photos'] ?? [],
                'notes' => $data['notes'] ?? null,
                'is_public' => $data['is_public'] ?? false,
                'local_record_id' => $localId,
            ]);
        } catch (\Throwable $e) {
            if ($localId !== null) {
                $winner = FishingHarvestLog::on('wildlife')
                    ->where('user_id', $userId)
                    ->where('local_record_id', $localId)
                    ->first();

                if ($winner) {
                    return $winner;
                }
            }

            throw $e;
        }

        $this->audit->log(
            eventType: 'fishing_harvest.logged',
            sourceDatabase: 'wildlife',
            tableName: 'fishing_harvest_logs',
            recordId: $catchId,
            userId: $userId,
            actionSummary: "Logged catch of {$data['species_code']} on lease ".strtoupper(substr($leaseId, 0, 8)),
        );

        return $catch;
    }

    /** @return Collection<int,FishingHarvestLog> */
    public function listForUser(string $userId, int $limit = 100): Collection
    {
        return FishingHarvestLog::on('wildlife')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('catch_date')
            ->limit($limit)
            ->get();
    }
}
