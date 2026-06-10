<?php

namespace App\Services\Property;

use App\Models\Geospatial\PropertyBoundary;
use App\Models\Geospatial\StandLocation;
use App\Models\Geospatial\CwdManagementZone;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeospatialService extends BaseService
{
    /**
     * Fetch a property boundary record by its geospatial UUID.
     * Uses the read replica for non-critical lookups.
     */
    public function getBoundary(string $boundaryId): ?PropertyBoundary
    {
        return $this->cache("geo:boundary:{$boundaryId}", function () use ($boundaryId) {
            return PropertyBoundary::on('geospatial_read')->find($boundaryId);
        }, ttlMinutes: 30);
    }

    /**
     * Get the most recent boundary for a property as a GeoJSON Feature.
     * Returns null if the property has no boundary set.
     */
    public function getPropertyBoundaryGeoJson(string $propertyId): ?array
    {
        return $this->cache("geo:boundary:property:{$propertyId}", function () use ($propertyId) {
            $row = DB::connection('geospatial_read')->selectOne(
                'SELECT ST_AsGeoJSON(boundary)::jsonb AS geometry, area_acres, source
                 FROM property_boundaries
                 WHERE property_id = ?
                   AND deleted_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 1',
                [$propertyId]
            );

            if (! $row) {
                return null;
            }

            return [
                'type'       => 'Feature',
                'geometry'   => json_decode($row->geometry, true),
                'properties' => [
                    'property_id' => $propertyId,
                    'area_acres'  => $row->area_acres,
                    'source'      => $row->source,
                ],
            ];
        }, ttlMinutes: 30);
    }

    /**
     * Store a property boundary from a GeoJSON MultiPolygon string.
     * Always uses the write connection. Invalidates the cached boundary.
     *
     * @return string  The new boundary UUID
     */
    public function storePropertyBoundary(string $propertyId, string $geoJsonMultiPolygon, string $source = 'manual'): string
    {
        $id = (string) Str::uuid();

        DB::connection('geospatial')->statement(
            'INSERT INTO property_boundaries (id, property_id, boundary, source)
             VALUES (?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?)',
            [$id, $propertyId, $geoJsonMultiPolygon, $source]
        );

        $this->invalidate(
            "geo:boundary:{$id}",
            "geo:boundary:property:{$propertyId}"
        );

        return $id;
    }

    /**
     * Check if a GPS point falls within a property's boundary.
     */
    public function isPointWithinProperty(string $propertyId, float $longitude, float $latitude): bool
    {
        $result = DB::connection('geospatial_read')->selectOne(
            'SELECT EXISTS (
                SELECT 1
                FROM property_boundaries
                WHERE property_id = ?
                  AND deleted_at IS NULL
                  AND ST_Within(ST_SetSRID(ST_MakePoint(?, ?), 4326), boundary)
            ) AS within',
            [$propertyId, $longitude, $latitude]
        );

        return (bool) $result?->within;
    }

    /**
     * Get all stands within a radius of a GPS point, ordered by distance.
     */
    public function getStandsNearPoint(float $longitude, float $latitude, int $radiusMeters = 500): \Illuminate\Support\Collection
    {
        $rows = DB::connection('geospatial_read')->select(
            'SELECT id, name, stand_type, elevation_ft,
                    ST_AsGeoJSON(location)::jsonb AS location_geojson,
                    ST_Distance(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters
             FROM stand_locations
             WHERE deleted_at IS NULL
               AND is_active = true
               AND ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
             ORDER BY distance_meters',
            [$longitude, $latitude, $longitude, $latitude, $radiusMeters]
        );

        return collect($rows)->map(function ($row) {
            $row->location_geojson = json_decode($row->location_geojson, true);
            return $row;
        });
    }

    /**
     * Get all stands on a property as GeoJSON features for the map UI.
     */
    public function getPropertyStandsGeoJson(string $propertyId): array
    {
        $rows = DB::connection('geospatial_read')->select(
            'SELECT id, name, stand_type, elevation_ft, is_active,
                    ST_AsGeoJSON(location)::jsonb AS geometry
             FROM stand_locations
             WHERE property_id = ?
               AND deleted_at IS NULL
             ORDER BY name',
            [$propertyId]
        );

        return [
            'type'     => 'FeatureCollection',
            'features' => collect($rows)->map(fn($r) => [
                'type'       => 'Feature',
                'geometry'   => json_decode($r->geometry, true),
                'properties' => [
                    'id'         => $r->id,
                    'name'       => $r->name,
                    'stand_type' => $r->stand_type,
                    'elevation_ft' => $r->elevation_ft,
                    'is_active'  => (bool) $r->is_active,
                ],
            ])->values()->all(),
        ];
    }

    /**
     * Store a stand location from a GeoJSON Point.
     *
     * @return string  The new stand UUID
     */
    public function storeStandLocation(string $propertyId, string $name, string $standType, float $longitude, float $latitude, ?int $elevationFt = null, ?string $leaseId = null): string
    {
        $id = (string) Str::uuid();

        DB::connection('geospatial')->statement(
            'INSERT INTO stand_locations (id, property_id, lease_id, name, stand_type, location, elevation_ft)
             VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?)',
            [$id, $propertyId, $leaseId, $name, $standType, $longitude, $latitude, $elevationFt]
        );

        return $id;
    }

    /**
     * Check if a property boundary intersects any CWD positive or management zones.
     * Returns an array of zone records.
     */
    public function getIntersectingCwdZones(string $propertyId): array
    {
        return DB::connection('geospatial_read')->select(
            'SELECT cwd.id, cwd.state_code, cwd.zone_name, cwd.zone_type, cwd.effective_date
             FROM cwd_management_zones cwd
             INNER JOIN property_boundaries pb ON ST_Intersects(pb.boundary, cwd.boundary)
             WHERE pb.property_id = ?
               AND pb.deleted_at IS NULL
               AND cwd.zone_type IN (\'positive\', \'management\')',
            [$propertyId]
        );
    }

    /**
     * Check if a single GPS point falls within any CWD zone.
     */
    public function getCwdZonesForPoint(float $longitude, float $latitude): array
    {
        return DB::connection('geospatial_read')->select(
            'SELECT id, state_code, zone_name, zone_type, effective_date
             FROM cwd_management_zones
             WHERE ST_Within(ST_SetSRID(ST_MakePoint(?, ?), 4326), boundary)',
            [$longitude, $latitude]
        );
    }

    /**
     * Invalidate all cached boundaries for a property.
     */
    public function invalidatePropertyCache(string $propertyId): void
    {
        $this->invalidate("geo:boundary:property:{$propertyId}");
    }
}
