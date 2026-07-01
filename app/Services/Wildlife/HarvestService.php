<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\HarvestLog;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\GeospatialService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Harvest logging — the field-operation write path.
 *
 * Order of operations is deliberate and each step is a guard:
 *   1. standing (WildlifeAccess) — the only authz boundary on DB 5.
 *   2. offline dedup on local_record_id — a replayed submission returns the
 *      existing row and never touches the quota again.
 *   3. CWD gate — harvesting in a positive zone requires an acknowledgment before
 *      anything is written (422 with the zones so the client can prompt).
 *   4. atomic quota claim — 0 rows updated => quota full => 409 (audited).
 *   5. GPS to DB 13 — coordinates never land in DB 5.
 *   6. insert + CWD acks + audit.
 *
 * If the insert fails after the quota was claimed (e.g. the unique index rejects a
 * racing replay) the claim is released so the count stays honest.
 */
class HarvestService extends BaseService
{
    public function __construct(
        private readonly WildlifeAccess $access,
        private readonly QuotaService $quotas,
        private readonly CwdService $cwd,
        private readonly GeospatialService $geo,
        private readonly AuditService $audit,
    ) {}

    /**
     * Log a harvest against an active lease the user has standing on.
     *
     * @param  array<string,mixed>  $data  species_code, harvest_date, weapon_type
     *                                     (required); harvest_time, antler_score, weight_lbs, age_estimate, notes,
     *                                     is_public, field_photos[]; latitude, longitude, gps_accuracy_m;
     *                                     local_record_id; cwd_acknowledged.
     */
    public function log(string $userId, string $leaseId, array $data): HarvestLog
    {
        $lease = $this->access->assertLeaseStanding($userId, $leaseId);
        $propertyId = $lease->property_id;

        // Offline dedup: a replayed record returns the original, no quota re-claim.
        $localId = $data['local_record_id'] ?? null;
        if ($localId !== null) {
            $existing = HarvestLog::on('wildlife')
                ->where('user_id', $userId)
                ->where('local_record_id', $localId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $speciesCode = $data['species_code'];
        $seasonYear = Carbon::parse($data['harvest_date'])->year;

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $hasPoint = $lat !== null && $lng !== null;

        // CWD compliance gate — before any side effect so a 422 is safe to retry.
        $requiredZones = $hasPoint
            ? $this->cwd->zonesRequiringAcknowledgment($lng, $lat)
            : collect();

        if ($requiredZones->isNotEmpty() && ! ($data['cwd_acknowledged'] ?? false)) {
            abort(422, 'CWD acknowledgment required: '.$requiredZones->pluck('zone_name')->join(', '));
        }

        // Atomic quota claim — reject a full quota before writing anything.
        if (! $this->quotas->tryConsume($propertyId, $leaseId, $speciesCode, $seasonYear)) {
            $this->audit->log(
                eventType: 'harvest.quota_exhausted',
                sourceDatabase: 'wildlife',
                tableName: 'harvest_quotas',
                recordId: $leaseId,
                userId: $userId,
                actionSummary: "Rejected harvest of {$speciesCode}: season {$seasonYear} quota exhausted",
            );

            abort(409, 'Harvest quota for this species is already full for the season.');
        }

        $harvestId = (string) Str::uuid();

        try {
            $geoId = $hasPoint
                ? $this->geo->storeHarvestLocation($harvestId, $lng, $lat, $data['gps_accuracy_m'] ?? null)
                : null;

            $harvest = HarvestLog::create([
                'id' => $harvestId,
                'lease_id' => $leaseId,
                'user_id' => $userId,
                'property_id' => $propertyId,
                'species_code' => $speciesCode,
                'harvest_date' => $data['harvest_date'],
                'harvest_time' => $data['harvest_time'] ?? null,
                'location_geospatial_id' => $geoId,
                'weapon_type' => $data['weapon_type'],
                'antler_score' => $data['antler_score'] ?? null,
                'weight_lbs' => $data['weight_lbs'] ?? null,
                'age_estimate' => $data['age_estimate'] ?? null,
                'field_photos' => $data['field_photos'] ?? [],
                'notes' => $data['notes'] ?? null,
                'is_public' => $data['is_public'] ?? false,
                'local_record_id' => $localId,
            ]);
        } catch (\Throwable $e) {
            // A racing replay lost the unique-index race, or the insert failed —
            // return the claimed tag so the count is not inflated.
            $this->quotas->release($propertyId, $leaseId, $speciesCode, $seasonYear);

            // If the loser of a replay race, hand back the winning row instead of erroring.
            if ($localId !== null) {
                $winner = HarvestLog::on('wildlife')
                    ->where('user_id', $userId)
                    ->where('local_record_id', $localId)
                    ->first();

                if ($winner) {
                    return $winner;
                }
            }

            throw $e;
        }

        foreach ($requiredZones as $zone) {
            $this->cwd->acknowledge($userId, $harvestId, $zone->id);
        }

        $this->audit->log(
            eventType: 'harvest.logged',
            sourceDatabase: 'wildlife',
            tableName: 'harvest_logs',
            recordId: $harvestId,
            userId: $userId,
            actionSummary: "Logged harvest of {$speciesCode} on lease ".strtoupper(substr($leaseId, 0, 8)),
        );

        // NOTE: harvest-photo virus scan + AI trophy scoring dispatch in Phase 6.4
        // (ScanHarvestPhoto / ScoreHarvestPhoto jobs). field_photos are not servable
        // until those jobs mark the documents ready.

        return $harvest;
    }

    /**
     * The caller's own harvest logs, newest first. Standing-scoped: only rows the
     * caller authored are returned (no cross-tenant read).
     *
     * @return Collection<int,HarvestLog>
     */
    public function listForUser(string $userId, int $limit = 100): Collection
    {
        return HarvestLog::on('wildlife')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('harvest_date')
            ->limit($limit)
            ->get();
    }

    /**
     * A single harvest the user is allowed to read (own record, standing on the
     * lease, or manages the property). 404 otherwise — never disclose existence.
     */
    public function findForUser(string $userId, string $harvestId): HarvestLog
    {
        $harvest = HarvestLog::on('wildlife')
            ->where('id', $harvestId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->access->assertRecordAccess($userId, $harvest->user_id, $harvest->lease_id, $harvest->property_id);

        return $harvest;
    }
}
