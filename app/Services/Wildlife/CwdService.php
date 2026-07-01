<?php

namespace App\Services\Wildlife;

use App\Models\Wildlife\CwdAcknowledgment;
use App\Models\Wildlife\CwdZone;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\GeospatialService;
use Illuminate\Support\Collection;

/**
 * Chronic Wasting Disease compliance.
 *
 * Precise zone geometry lives in DB 13 (PostGIS); the regulatory metadata that a
 * hunter must read and acknowledge lives in DB 5 (cwd_zones). This service joins
 * the two in PHP: a GPS point resolves to zone rows in DB 13, matched back to the
 * DB 5 metadata by (state_code, zone_name). Harvesting in a `positive` zone can
 * legally require sample submission, so the acknowledgment is recorded and audited.
 */
class CwdService extends BaseService
{
    public function __construct(
        private readonly GeospatialService $geo,
        private readonly AuditService $audit,
    ) {}

    /**
     * CWD zone metadata (DB 5) covering a GPS point, resolved via the DB 13
     * geometry. Empty when the point is in no zone.
     *
     * @return Collection<int,CwdZone>
     */
    public function zonesForPoint(float $longitude, float $latitude): Collection
    {
        $geoZones = $this->geo->getCwdZonesForPoint($longitude, $latitude);

        if ($geoZones === []) {
            return collect();
        }

        return collect($geoZones)
            ->map(fn ($z) => CwdZone::on('wildlife')
                ->where('state_code', $z->state_code)
                ->where('zone_name', $z->zone_name)
                ->first())
            ->filter()
            ->values();
    }

    /**
     * The zones at a point that legally require an acknowledgment on harvest
     * (positive zones). Empty when no acknowledgment is required.
     *
     * @return Collection<int,CwdZone>
     */
    public function zonesRequiringAcknowledgment(float $longitude, float $latitude): Collection
    {
        return $this->zonesForPoint($longitude, $latitude)
            ->filter(fn (CwdZone $z) => $z->requiresAcknowledgment())
            ->values();
    }

    /**
     * Record a hunter's CWD acknowledgment for a harvest in a positive zone.
     * Idempotent per (harvest, zone) via the table's unique index. Audited.
     */
    public function acknowledge(string $userId, string $harvestLogId, string $cwdZoneId): CwdAcknowledgment
    {
        $existing = CwdAcknowledgment::on('wildlife')
            ->where('harvest_log_id', $harvestLogId)
            ->where('cwd_zone_id', $cwdZoneId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $ack = CwdAcknowledgment::create([
            'user_id' => $userId,
            'harvest_log_id' => $harvestLogId,
            'cwd_zone_id' => $cwdZoneId,
            'acknowledged_at' => now(),
        ]);

        $this->audit->log(
            eventType: 'cwd.acknowledged',
            sourceDatabase: 'wildlife',
            tableName: 'cwd_acknowledgments',
            recordId: $ack->id,
            userId: $userId,
            actionSummary: 'Hunter acknowledged CWD sampling requirement for a harvest in a positive zone',
        );

        return $ack;
    }
}
