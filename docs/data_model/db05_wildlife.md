# DB 5 — Wildlife & Field Operations

**Connection (write):** `wildlife`
**Connection (read):** `wildlife_read`
**Database:** `ah_wildlife`
**Write User:** `ah_app`
**Read User:** `ah_readonly`
**Server:** High-write optimized PostgreSQL — NVMe SSD, write-optimized `postgresql.conf`, high WAL throughput
**Encryption Key:** Key E — rotated annually via Azure Key Vault
**Extensions:** `uuid-ossp`
**RLS Enabled:** No — field operation records are access-controlled at the service layer, not DB RLS

This database stores all in-field data: harvest logs, wildlife sightings, trail camera data and AI-processed photos, species harvest quotas, and reference data for hunting seasons and CWD zones. It is the highest-write database in the application during peak season.

All property and lease references are cross-DB UUID columns. Geospatial locations (stand coordinates, harvest points) live in DB 13 and are referenced by ID.

Route all reporting and analytics reads (e.g., "show me all harvests for this property this season") to `wildlife_read`. Route harvest log creation and trail camera updates to `wildlife`.

---

## Tables

### `harvest_logs`

The primary record of every confirmed harvest (animal taken). This is the most important data asset in the wildlife database — it feeds quota tracking, population modeling (DB 14), and the trophy scoring feature.

```sql
CREATE TABLE harvest_logs (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id              UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
    user_id               UUID        NOT NULL,  -- References DB 1 (Identity) users.id — hunter who harvested
    property_id           UUID        NOT NULL,  -- References DB 2 (Property) properties.id
    species_code          VARCHAR(50) NOT NULL
                              CHECK (species_code IN (
                                  'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                                  'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                                  'rabbit', 'squirrel', 'coyote', 'other'
                              )),
    harvest_date          DATE        NOT NULL,
    harvest_time          TIME        NULL,
    location_geospatial_id UUID       NULL,  -- References DB 13 (Geospatial) harvest_locations.id
    weapon_type           VARCHAR(20) NOT NULL
                              CHECK (weapon_type IN ('bow', 'rifle', 'shotgun', 'muzzleloader', 'pistol', 'other')),
    antler_score          NUMERIC(6,2) NULL,  -- Boone & Crockett / Pope & Young score
    weight_lbs            NUMERIC(6,2) NULL,
    age_estimate          VARCHAR(20) NULL,   -- '1.5', '2.5', '3.5+', 'fawn', 'unknown'
    field_photos          JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
    notes                 TEXT        NULL,
    is_public             BOOLEAN     NOT NULL DEFAULT false,  -- show in public trophy gallery
    ai_score              NUMERIC(6,2) NULL,   -- AI-computed antler score (feature flag: ai_trophy_scoring)
    ai_scored_at          TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at            TIMESTAMPTZ NULL
);

CREATE INDEX idx_harvest_logs_lease_id       ON harvest_logs (lease_id);
CREATE INDEX idx_harvest_logs_user_id        ON harvest_logs (user_id);
CREATE INDEX idx_harvest_logs_property_id    ON harvest_logs (property_id);
CREATE INDEX idx_harvest_logs_species_code   ON harvest_logs (species_code);
CREATE INDEX idx_harvest_logs_harvest_date   ON harvest_logs (harvest_date);
CREATE INDEX idx_harvest_logs_deleted_at     ON harvest_logs (deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX idx_harvest_logs_field_photos   ON harvest_logs USING GIN (field_photos)
    WHERE jsonb_array_length(field_photos) > 0;

CREATE TRIGGER trg_harvest_logs_updated_at
    BEFORE UPDATE ON harvest_logs
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `field_photos` is a JSONB array of document UUIDs: `["<uuid1>", "<uuid2>"]`. Resolve URLs via `DocumentService::getUrl()`.
- `antler_score` is entered by the hunter. `ai_score` is computed asynchronously by `ScoreHarvestPhotoJob` (feature flag: `ai_trophy_scoring`).
- After insert, `UpdateHarvestQuotaJob` increments `harvest_quotas.current_harvest` for the property + species + season combination.
- `is_public = true` allows the harvest to appear in the public trophy gallery and leaderboard.

---

### `wildlife_sightings`

Non-harvest wildlife observations — deer sightings, flock counts, trail camera manual entries. Used for population modeling and scouting intelligence shared with lessees.

```sql
CREATE TABLE wildlife_sightings (
    id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
    user_id                UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    property_id            UUID        NOT NULL,  -- References DB 2 (Property) properties.id
    species_code           VARCHAR(50) NOT NULL
                               CHECK (species_code IN (
                                   'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                                   'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                                   'rabbit', 'squirrel', 'coyote', 'other', 'unknown'
                               )),
    sighting_date          DATE        NOT NULL,
    sighting_time          TIME        NULL,
    count                  SMALLINT    NOT NULL DEFAULT 1,
    location_geospatial_id UUID        NULL,  -- References DB 13 (Geospatial) sighting_locations.id
    notes                  TEXT        NULL,
    photo_document_ids     JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at             TIMESTAMPTZ NULL
);

CREATE INDEX idx_wildlife_sightings_lease_id    ON wildlife_sightings (lease_id);
CREATE INDEX idx_wildlife_sightings_user_id     ON wildlife_sightings (user_id);
CREATE INDEX idx_wildlife_sightings_property_id ON wildlife_sightings (property_id);
CREATE INDEX idx_wildlife_sightings_species     ON wildlife_sightings (species_code);
CREATE INDEX idx_wildlife_sightings_date        ON wildlife_sightings (sighting_date);
CREATE INDEX idx_wildlife_sightings_deleted_at  ON wildlife_sightings (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_wildlife_sightings_updated_at
    BEFORE UPDATE ON wildlife_sightings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `trail_cameras`

A trail camera installed on a property. Tracks device status and location. High-volume photo inserts come from `trail_camera_photos`.

```sql
CREATE TABLE trail_cameras (
    id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
    property_id            UUID        NOT NULL,  -- References DB 2 (Property) properties.id
    user_id                UUID        NOT NULL,  -- References DB 1 (Identity) users.id — camera owner
    name                   VARCHAR(100) NOT NULL,
    model                  VARCHAR(100) NULL,
    location_geospatial_id UUID        NULL,  -- References DB 13 (Geospatial) stand_locations.id
    status                 VARCHAR(10) NOT NULL DEFAULT 'active'
                               CHECK (status IN ('active', 'offline', 'inactive')),
    last_photo_at          TIMESTAMPTZ NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at             TIMESTAMPTZ NULL
);

CREATE INDEX idx_trail_cameras_lease_id    ON trail_cameras (lease_id);
CREATE INDEX idx_trail_cameras_property_id ON trail_cameras (property_id);
CREATE INDEX idx_trail_cameras_user_id     ON trail_cameras (user_id);
CREATE INDEX idx_trail_cameras_status      ON trail_cameras (status);
CREATE INDEX idx_trail_cameras_deleted_at  ON trail_cameras (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_trail_cameras_updated_at
    BEFORE UPDATE ON trail_cameras
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `trail_camera_photos`

Individual photos from trail cameras. Very high insert volume during peak season. AI species detection runs asynchronously after insert.

```sql
CREATE TABLE trail_camera_photos (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    camera_id        UUID        NOT NULL REFERENCES trail_cameras (id) ON DELETE CASCADE,
    document_id      UUID        NOT NULL,  -- References DB 11 (Documents) documents.id
    taken_at         TIMESTAMPTZ NOT NULL,
    species_detected JSONB       NOT NULL DEFAULT '[]',
        -- array of: {"species_code": "whitetail_deer", "confidence": 0.94, "count": 2}
    ai_processed_at  TIMESTAMPTZ NULL,
    ai_confidence    NUMERIC(4,3) NULL CHECK (ai_confidence BETWEEN 0 AND 1),
    is_flagged       BOOLEAN     NOT NULL DEFAULT false,  -- flagged for manual review
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ NULL
);

CREATE INDEX idx_trail_camera_photos_camera_id   ON trail_camera_photos (camera_id);
CREATE INDEX idx_trail_camera_photos_taken_at    ON trail_camera_photos (camera_id, taken_at DESC);
CREATE INDEX idx_trail_camera_photos_flagged     ON trail_camera_photos (is_flagged) WHERE is_flagged = true;
CREATE INDEX idx_trail_camera_photos_species_gin ON trail_camera_photos USING GIN (species_detected);
```

**Notes:**
- `document_id` resolves to the actual image file via `DocumentService`. Photos go through virus scan (`ScanUploadedFileJob`) before being marked accessible.
- `TrailCameraAiTaggingJob` (default queue) processes photos after upload and populates `species_detected`, `ai_confidence`, and `ai_processed_at`.
- `is_flagged` is set if AI confidence is below threshold (< 0.50) or if a staff member flags it for review.
- Consider range partitioning on `taken_at` by month if table exceeds 100M rows.

---

### `harvest_quotas`

Per-property (and optionally per-lease) species harvest limits. Enforced by `QuotaService` before a harvest log is saved.

```sql
CREATE TABLE harvest_quotas (
    id               UUID     NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id      UUID     NOT NULL,  -- References DB 2 (Property) properties.id
    lease_id         UUID     NULL,      -- References DB 3 (Lease) leases.id — null for property-wide quota
    species_code     VARCHAR(50) NOT NULL,
    season_year      SMALLINT NOT NULL,
    max_harvest      SMALLINT NOT NULL,
    current_harvest  SMALLINT NOT NULL DEFAULT 0,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_harvest_quotas_counts CHECK (current_harvest >= 0 AND max_harvest > 0),
    CONSTRAINT chk_harvest_quotas_not_exceeded CHECK (current_harvest <= max_harvest)
);

CREATE UNIQUE INDEX uq_harvest_quotas_property_lease_species_year
    ON harvest_quotas (property_id, COALESCE(lease_id, '00000000-0000-0000-0000-000000000000'::UUID), species_code, season_year);
CREATE INDEX idx_harvest_quotas_property_id ON harvest_quotas (property_id);
CREATE INDEX idx_harvest_quotas_lease_id    ON harvest_quotas (lease_id) WHERE lease_id IS NOT NULL;

CREATE TRIGGER trg_harvest_quotas_updated_at
    BEFORE UPDATE ON harvest_quotas
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `current_harvest` is incremented atomically by `QuotaService::incrementAndCheck()` using `UPDATE ... WHERE current_harvest < max_harvest RETURNING *`. If the update returns no rows, the quota is full and the harvest is rejected.
- `lease_id = NULL` means the quota applies to the property overall (all lessees combined). A lease-specific quota overrides the property quota for that lease.
- Quotas are set by the landowner in the admin portal or via a default rule in DB 12 platform settings.

---

### `seasons`

Reference data for hunting season dates by state and species. Used to validate harvest log dates and to display season calendars. Seeded from state wildlife agency data and updated annually.

```sql
CREATE TABLE seasons (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code   CHAR(2)     NOT NULL,
    species_code VARCHAR(50) NOT NULL,
    season_name  VARCHAR(100) NOT NULL,
    season_type  VARCHAR(20) NOT NULL
                     CHECK (season_type IN ('archery', 'rifle', 'muzzleloader', 'general', 'youth', 'special')),
    start_date   DATE        NOT NULL,
    end_date     DATE        NOT NULL,
    year         SMALLINT    NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_seasons_dates CHECK (end_date >= start_date)
);

CREATE UNIQUE INDEX uq_seasons_state_species_type_year
    ON seasons (state_code, species_code, season_type, year);
CREATE INDEX idx_seasons_state_code   ON seasons (state_code);
CREATE INDEX idx_seasons_species_code ON seasons (species_code);
CREATE INDEX idx_seasons_year         ON seasons (year);
CREATE INDEX idx_seasons_dates        ON seasons (start_date, end_date);

CREATE TRIGGER trg_seasons_updated_at
    BEFORE UPDATE ON seasons
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `cwd_zones`

Chronic Wasting Disease (CWD) zone reference data. Used to display regulatory warnings and mandatory reporting requirements to hunters. Updated as state agencies publish new zone boundaries.

```sql
CREATE TABLE cwd_zones (
    id             UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code     CHAR(2)     NOT NULL,
    zone_name      VARCHAR(100) NOT NULL,
    zone_type      VARCHAR(15) NOT NULL
                       CHECK (zone_type IN ('positive', 'surveillance', 'management')),
    regulations    TEXT        NULL,  -- plain-text summary of zone-specific regulations
    effective_date DATE        NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_cwd_zones_state_code ON cwd_zones (state_code);
CREATE INDEX idx_cwd_zones_zone_type  ON cwd_zones (zone_type);

CREATE TRIGGER trg_cwd_zones_updated_at
    BEFORE UPDATE ON cwd_zones
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- CWD zone geographic boundaries (polygons) live in DB 13 (Geospatial) as `cwd_zone_boundaries`. This table stores the regulatory metadata. The `GeospatialService` links them via `cwd_zone_id`.
- `zone_type = 'positive'` means CWD has been confirmed in this zone. Hunters harvesting deer in positive zones may be required to submit head samples for testing — `regulations` explains the specific requirements.

---

### `population_surveys`

Annual wildlife population estimates for a property. Entered by landowners or staff based on game camera surveys, helicopter counts, or observation data. Used in DB 14 population modeling (ETL-only).

```sql
CREATE TABLE population_surveys (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id      UUID        NOT NULL,  -- References DB 2 (Property) properties.id
    species_code     VARCHAR(50) NOT NULL,
    survey_year      SMALLINT    NOT NULL,
    method           VARCHAR(50) NOT NULL,  -- 'game_camera', 'aerial', 'observation', 'track_count', etc.
    estimated_count  SMALLINT    NULL,
    buck_doe_ratio   NUMERIC(4,2) NULL,     -- for deer: number of bucks per 100 does
    notes            TEXT        NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_population_surveys_property_species_year
    ON population_surveys (property_id, species_code, survey_year);
CREATE INDEX idx_population_surveys_property_id ON population_surveys (property_id);
CREATE INDEX idx_population_surveys_year        ON population_surveys (survey_year);

CREATE TRIGGER trg_population_surveys_updated_at
    BEFORE UPDATE ON population_surveys
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

## Eloquent Models

### `App\Models\Wildlife\HarvestLog`

```php
<?php

namespace App\Models\Wildlife;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HarvestLog extends Model
{
    use SoftDeletes;

    protected $connection = 'wildlife';
    protected $table      = 'harvest_logs';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'lease_id',
        'user_id',
        'property_id',
        'species_code',
        'harvest_date',
        'harvest_time',
        'location_geospatial_id',
        'weapon_type',
        'antler_score',
        'weight_lbs',
        'age_estimate',
        'field_photos',
        'notes',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'harvest_date'  => 'date',
            'field_photos'  => 'array',
            'is_public'     => 'boolean',
            'ai_scored_at'  => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'deleted_at'    => 'datetime',
        ];
    }

    // Cross-DB: resolve photo URLs via DocumentService
    public function getPhotoUrls(): array
    {
        $docService = app(\App\Services\Documents\DocumentService::class);
        return collect($this->field_photos)
            ->map(fn(string $id) => $docService->getUrl($id))
            ->all();
    }

    // Cross-DB: resolved via UserService
    public function getHunter(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->user_id);
    }
}
```

### `App\Models\Wildlife\TrailCamera`

```php
protected $connection = 'wildlife';
protected $table      = 'trail_cameras';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'last_photo_at' => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];
}
```

### `App\Models\Wildlife\TrailCameraPhoto`

```php
protected $connection = 'wildlife';
protected $table      = 'trail_camera_photos';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'species_detected' => 'array',
        'ai_processed_at'  => 'datetime',
        'is_flagged'       => 'boolean',
        'taken_at'         => 'datetime',
        'created_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];
}
```

### `App\Models\Wildlife\HarvestQuota`

```php
protected $connection = 'wildlife';
protected $table      = 'harvest_quotas';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';
```

---

## Service Notes

- **`HarvestService`** — creates harvest logs, triggers quota checks, dispatches `ScoreHarvestPhotoJob`. At `App\Services\Wildlife\HarvestService`. Reads via `wildlife_read`, writes via `wildlife`.
- **`QuotaService`** — atomic quota increment/check logic. At `App\Services\Wildlife\QuotaService`. Uses `wildlife` connection with `SELECT ... FOR UPDATE` row locking to prevent over-harvest races.
- **`CwdService`** — checks if a harvest location falls within a CWD zone (via `GeospatialService`) and returns zone regulations. At `App\Services\Wildlife\CwdService`.
- **Queue jobs:** `TrailCameraAiTaggingJob` (default), `ScoreHarvestPhotoJob` (default), `ScanUploadedFileJob` (default), `UpdateHarvestQuotaJob` (default).
- Read-heavy endpoints (property harvest history, trail camera galleries, leaderboards) must use the `wildlife_read` connection to avoid write-replica lag affecting writes.
