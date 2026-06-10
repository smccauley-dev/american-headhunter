# DB 13 — Geospatial

**Server:** Dedicated PostGIS instance (PostgreSQL 16 + PostGIS 3.x)
**Encryption Key:** Key M — rotated annually
**Laravel Connection:** `geospatial` (write), `geospatial_read` (read replica)
**Database:** `ah_geospatial`
**Writer User:** `ah_app` | **Reader User:** `ah_readonly`
**Access:** GeospatialService (write), all read-heavy services via `geospatial_read`, Mapbox tile generation worker

---

## CRITICAL RULE

**All geometry lives in this database and is NEVER duplicated into other databases.**

Other databases store only a UUID that references a row in DB 13. For example: `DB 2 properties` has a column `boundary_id UUID -- References DB 13 (Geospatial) property_boundaries.id`. The actual `GEOMETRY` column exists only here.

Cross-DB geometry lookups always go through `GeospatialService`. Never query DB 13 from a controller or model that belongs to another domain.

---

## Purpose

All geometric and geographic data for the platform. Dedicated PostGIS instance allows spatial indexes and query optimization without impacting transactional databases. Stores property boundaries, stand locations, food plots, harvest GPS points, trail camera placements, CWD zone polygons, and SOS GPS snapshots. Powers map UI (Mapbox GL JS), property boundary intersections, distance queries, heat maps, and conservation zone overlays.

---

## Extensions Required

```sql
CREATE EXTENSION IF NOT EXISTS "postgis";
CREATE EXTENSION IF NOT EXISTS "postgis_topology";
CREATE EXTENSION IF NOT EXISTS "fuzzystrmatch";
CREATE EXTENSION IF NOT EXISTS "postgis_tiger_geocoder";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

---

## Coordinate Convention

All geometry uses **SRID 4326 (WGS84)** — standard GPS coordinates (longitude, latitude). For area calculations, geometries are projected to **SRID 5070 (NAD83 / Conus Albers)**, a US equal-area projection, before calling `ST_Area()`. The `area_acres` generated columns handle this automatically.

```sql
-- Area calculation pattern used in GENERATED columns:
ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
-- 4046.856422 = square meters per acre
```

---

## Shared Trigger

```sql
CREATE OR REPLACE FUNCTION trigger_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
```

---

## Tables

### property_boundaries
The legal or mapped boundary of a property. One boundary per property in the typical case. The `area_acres` column is a stored generated column — it is always consistent with the geometry without any application logic.

```sql
CREATE TABLE property_boundaries (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id UUID NOT NULL,           -- References DB 2 (Property) properties.id
    boundary    GEOMETRY(MULTIPOLYGON, 4326) NOT NULL,
    area_acres  NUMERIC(12,4) GENERATED ALWAYS AS (
                    ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
                ) STORED,
    source      VARCHAR(20) NOT NULL DEFAULT 'manual',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ,

    CONSTRAINT chk_property_boundaries_source
        CHECK (source IN ('manual', 'gps_import', 'parcel_data'))
);

CREATE INDEX idx_property_boundaries_property_id ON property_boundaries (property_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_property_boundaries_boundary_gist ON property_boundaries USING GIST (boundary)
    WHERE deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON property_boundaries
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### stand_locations
Individual hunting stands and blinds placed on a property. May be associated with a specific lease to control hunter visibility.

```sql
CREATE TABLE stand_locations (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id UUID NOT NULL,           -- References DB 2 (Property) properties.id
    lease_id    UUID,                    -- References DB 3 (Lease) leases.id — NULL = visible to all lessees
    name        VARCHAR(100) NOT NULL,
    stand_type  VARCHAR(20) NOT NULL,
    location    GEOMETRY(POINT, 4326) NOT NULL,
    elevation_ft SMALLINT,
    notes       TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ,

    CONSTRAINT chk_stand_locations_type
        CHECK (stand_type IN (
            'ladder', 'climbing', 'ground_blind', 'box_blind', 'tripod', 'shooting_house'
        ))
);

CREATE INDEX idx_stand_locations_property_id ON stand_locations (property_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_stand_locations_lease_id ON stand_locations (lease_id)
    WHERE lease_id IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_stand_locations_location_gist ON stand_locations USING GIST (location)
    WHERE deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON stand_locations
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### food_plots
Food plot polygon boundaries on a property. Used for field maps and harvest correlation analysis.

```sql
CREATE TABLE food_plots (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id     UUID NOT NULL,           -- References DB 2 (Property) properties.id
    name            VARCHAR(100) NOT NULL,
    boundary        GEOMETRY(POLYGON, 4326) NOT NULL,
    area_acres      NUMERIC(8,4) GENERATED ALWAYS AS (
                        ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
                    ) STORED,
    species_planted JSONB NOT NULL DEFAULT '[]',    -- e.g. ["clover", "soybeans", "corn"]
    planted_date    DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ
);

CREATE INDEX idx_food_plots_property_id ON food_plots (property_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_food_plots_boundary_gist ON food_plots USING GIST (boundary)
    WHERE deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON food_plots
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### harvest_locations
GPS point where a harvest occurred. Written when a hunter submits a harvest log in DB 5. Immutable once created — if the GPS point was wrong, a correction harvest log entry is preferred.

```sql
CREATE TABLE harvest_locations (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    harvest_log_id  UUID NOT NULL,               -- References DB 5 (Wildlife) harvest_logs.id
    location        GEOMETRY(POINT, 4326) NOT NULL,
    accuracy_meters SMALLINT,                    -- GPS accuracy reported by the device
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No updated_at — immutable once submitted
    -- No deleted_at — tied to harvest log lifecycle in DB 5
);

CREATE INDEX idx_harvest_locations_harvest_log_id ON harvest_locations (harvest_log_id);
CREATE INDEX idx_harvest_locations_location_gist ON harvest_locations USING GIST (location);
```

---

### trail_camera_locations
GPS placement point for each trail camera. Updated when a camera is physically moved.

```sql
CREATE TABLE trail_camera_locations (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    camera_id       UUID NOT NULL,               -- References DB 5 (Wildlife) trail_cameras.id
    location        GEOMETRY(POINT, 4326) NOT NULL,
    facing_direction SMALLINT,                   -- Compass bearing 0–359; NULL if unknown
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No deleted_at — history is preserved; inactive cameras are marked in DB 5

    CONSTRAINT chk_trail_camera_locations_direction
        CHECK (facing_direction IS NULL OR (facing_direction >= 0 AND facing_direction <= 359))
);

CREATE INDEX idx_trail_camera_locations_camera_id ON trail_camera_locations (camera_id);
CREATE INDEX idx_trail_camera_locations_location_gist ON trail_camera_locations USING GIST (location);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON trail_camera_locations
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### cwd_management_zones
Chronic Wasting Disease (CWD) zone polygons sourced from state wildlife agencies. Used to warn hunters when harvesting in or near affected zones and to power CWD compliance features.

```sql
CREATE TABLE cwd_management_zones (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code      CHAR(2) NOT NULL,
    zone_name       VARCHAR(100) NOT NULL,
    zone_type       VARCHAR(20) NOT NULL,
    boundary        GEOMETRY(MULTIPOLYGON, 4326) NOT NULL,
    effective_date  DATE NOT NULL,
    source_url      TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No deleted_at — superseded zones are replaced by ETL with new effective_date rows
);

CREATE INDEX idx_cwd_zones_state ON cwd_management_zones (state_code, zone_type);
CREATE INDEX idx_cwd_zones_boundary_gist ON cwd_management_zones USING GIST (boundary);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON cwd_management_zones
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

ALTER TABLE cwd_management_zones ADD CONSTRAINT chk_cwd_zones_type
    CHECK (zone_type IN ('positive', 'surveillance', 'management'));
```

---

### sos_locations
GPS snapshot recorded at the moment a user triggers an SOS alert. Immutable — it is the location at the time of the emergency. These records are permanent, consistent with the life-safety nature of SOS events.

```sql
CREATE TABLE sos_locations (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    sos_event_log_id    UUID NOT NULL,           -- References DB 7 (Communications) sos_event_log.id
    location            GEOMETRY(POINT, 4326) NOT NULL,
    accuracy_meters     SMALLINT,
    recorded_at         TIMESTAMPTZ NOT NULL,
    -- No updated_at — immutable
    -- No deleted_at — permanent life-safety record
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sos_locations_sos_event_log_id ON sos_locations (sos_event_log_id);
CREATE INDEX idx_sos_locations_location_gist ON sos_locations USING GIST (location);
CREATE INDEX idx_sos_locations_recorded_at ON sos_locations (recorded_at DESC);
```

---

## Eloquent Models

All geospatial models use the `geospatial` connection for writes and rely on `DB::connection('geospatial_read')` for read-heavy operations via the service layer. Geometry values are returned as WKT (Well-Known Text) strings from PostgreSQL and are handled by the service layer rather than cast in Eloquent.

```php
namespace App\Models\Geospatial;

class PropertyBoundary extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'geospatial';
    protected $table      = 'property_boundaries';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'area_acres' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class StandLocation extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'geospatial';
    protected $table      = 'stand_locations';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class FoodPlot extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'geospatial';
    protected $table      = 'food_plots';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'species_planted' => 'array',
            'area_acres'      => 'decimal:4',
            'planted_date'    => 'date',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class HarvestLocation extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'geospatial';
    protected $table      = 'harvest_locations';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class TrailCameraLocation extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'geospatial';
    protected $table      = 'trail_camera_locations';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class CwdManagementZone extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'geospatial';
    protected $table      = 'cwd_management_zones';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Geospatial;

class SosLocation extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'geospatial';
    protected $table      = 'sos_locations';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
```

---

## GeospatialService — Common Query Patterns

All spatial queries go through `App\Services\Property\GeospatialService`. Use the `geospatial_read` connection for read-heavy queries to avoid impacting the write instance.

### Check if a point is within a property boundary

```php
namespace App\Services\Property;

class GeospatialService
{
    /**
     * Returns true if the given GPS point falls within the property's boundary.
     *
     * @param  string  $propertyId   DB 2 property UUID
     * @param  float   $longitude
     * @param  float   $latitude
     */
    public function isPointWithinProperty(string $propertyId, float $longitude, float $latitude): bool
    {
        $result = DB::connection('geospatial_read')
            ->selectOne(<<<SQL
                SELECT EXISTS (
                    SELECT 1
                    FROM property_boundaries
                    WHERE property_id = ?
                      AND deleted_at IS NULL
                      AND ST_Within(
                            ST_SetSRID(ST_MakePoint(?, ?), 4326),
                            boundary
                          )
                ) AS within
            SQL, [$propertyId, $longitude, $latitude]);

        return (bool) $result->within;
    }

    /**
     * Find all stands within a given radius of a GPS point.
     *
     * @param  float   $longitude
     * @param  float   $latitude
     * @param  int     $radiusMeters
     * @return \Illuminate\Support\Collection
     */
    public function getStandsNearPoint(float $longitude, float $latitude, int $radiusMeters = 500): \Illuminate\Support\Collection
    {
        return DB::connection('geospatial_read')
            ->table('stand_locations')
            ->selectRaw(<<<SQL
                id,
                name,
                stand_type,
                ST_AsGeoJSON(location)::jsonb AS location_geojson,
                ST_Distance(
                    location::geography,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                ) AS distance_meters
            SQL, [$longitude, $latitude])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw(<<<SQL
                ST_DWithin(
                    location::geography,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                    ?
                )
            SQL, [$longitude, $latitude, $radiusMeters])
            ->orderBy('distance_meters')
            ->get();
    }

    /**
     * Check if a property boundary intersects any CWD positive or management zones.
     *
     * @param  string  $propertyId
     * @return array   Array of CwdManagementZone records that intersect
     */
    public function getIntersectingCwdZones(string $propertyId): array
    {
        return DB::connection('geospatial_read')
            ->select(<<<SQL
                SELECT cwd.id, cwd.state_code, cwd.zone_name, cwd.zone_type, cwd.effective_date
                FROM cwd_management_zones cwd
                INNER JOIN property_boundaries pb ON ST_Intersects(pb.boundary, cwd.boundary)
                WHERE pb.property_id = ?
                  AND pb.deleted_at IS NULL
                  AND cwd.zone_type IN ('positive', 'management')
            SQL, [$propertyId]);
    }

    /**
     * Get a property boundary as a GeoJSON FeatureCollection for the Mapbox frontend.
     *
     * @param  string  $propertyId
     * @return array|null
     */
    public function getPropertyBoundaryGeoJson(string $propertyId): ?array
    {
        $row = DB::connection('geospatial_read')
            ->selectOne(<<<SQL
                SELECT
                    ST_AsGeoJSON(boundary)::jsonb AS geometry,
                    area_acres,
                    source
                FROM property_boundaries
                WHERE property_id = ?
                  AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            SQL, [$propertyId]);

        if (!$row) {
            return null;
        }

        return [
            'type'       => 'Feature',
            'geometry'   => $row->geometry,
            'properties' => [
                'property_id' => $propertyId,
                'area_acres'  => $row->area_acres,
                'source'      => $row->source,
            ],
        ];
    }

    /**
     * Store a property boundary from a GeoJSON MultiPolygon.
     * Always uses the write connection.
     *
     * @param  string  $propertyId
     * @param  string  $geoJsonMultiPolygon  Raw GeoJSON geometry string
     * @param  string  $source
     */
    public function storePropertyBoundary(string $propertyId, string $geoJsonMultiPolygon, string $source = 'manual'): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::connection('geospatial')
            ->statement(<<<SQL
                INSERT INTO property_boundaries (id, property_id, boundary, source)
                VALUES (?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?)
            SQL, [$id, $propertyId, $geoJsonMultiPolygon, $source]);

        return $id;
    }
}
```

---

## Migration Conventions

PostGIS geometry columns **cannot** be created with Laravel's `Schema::create()` — always use raw SQL statements.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            CREATE TABLE property_boundaries (
                id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id UUID NOT NULL,
                boundary    GEOMETRY(MULTIPOLYGON, 4326) NOT NULL,
                area_acres  NUMERIC(12,4) GENERATED ALWAYS AS (
                                ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
                            ) STORED,
                source      VARCHAR(20) NOT NULL DEFAULT 'manual',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at  TIMESTAMPTZ,

                CONSTRAINT chk_property_boundaries_source
                    CHECK (source IN ('manual', 'gps_import', 'parcel_data'))
            )
        SQL);

        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_property_boundaries_property_id ON property_boundaries (property_id) WHERE deleted_at IS NULL'
        );

        DB::connection($this->connection)->statement(
            'CREATE INDEX idx_property_boundaries_boundary_gist ON property_boundaries USING GIST (boundary) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_boundaries');
    }
};
```

---

## Common Pitfalls

- **Never use `Schema::create()` for geospatial tables.** Laravel's schema builder does not understand PostGIS geometry types. Always use raw SQL via `DB::connection($this->connection)->statement()`.
- **Never store geometry in any other database.** If a DB 2 property needs its boundary, store the `property_boundaries.id` UUID in DB 2 as `boundary_id`, and fetch the geometry via `GeospatialService`.
- **Use `::geography` cast for distance queries** (`ST_Distance`, `ST_DWithin`) to get results in meters. Without the cast, results are in degrees.
- **Use `geospatial_read` for all read queries in high-traffic paths.** The write instance is for inserts/updates only.
- **`ST_AsGeoJSON()` returns TEXT.** Cast to `::jsonb` in the SELECT to avoid double-encoding when returning to the frontend.
- **Generated `area_acres` columns are always consistent.** Do not calculate acreage in application code — read it from the column.
- **SOS locations are permanent.** Never delete from `sos_locations`. The record is permanent for the same reason as `sos_event_log` in DB 7.
