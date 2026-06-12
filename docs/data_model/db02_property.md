# DB 2 — Property & Land

**Connection (write):** `property`
**Connection (read):** `property_read`
**Database:** `ah_property`
**Write User:** `ah_app`
**Read User:** `ah_readonly`
**Server:** High-memory standard PostgreSQL instance — optimized for full-text search and listing browse
**Encryption Key:** Key B — rotated annually via Azure Key Vault
**Extensions:** `uuid-ossp`
**RLS Enabled:** Yes — on `property_access_info` (lessees of that property only), `property_availability`

This database stores all land parcel data, published listings, photos, amenities, species, rules, access information, and hunter wishlists. It is the primary data source for the public property search and the member portal lease management view.

All owner references (`owner_user_id`) are cross-DB UUIDs pointing to `ah_identity.users.id`. Geometry data (property boundaries, stand locations) lives exclusively in DB 13 (Geospatial) and is referenced here by `boundary_geospatial_id`.

Route all search, browse, and discovery queries to `property_read`. Route all write operations (creating listings, updating properties, uploading photos) to `property`.

---

## Tables

### `properties`

The land parcel itself. One `property` record represents a piece of real estate. A property may have many listings over time (different seasons, listing types, price changes).

```sql
CREATE TABLE properties (
    id                        UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    owner_user_id             UUID         NOT NULL,  -- References DB 1 (Identity) users.id
    title                     VARCHAR(255) NOT NULL,
    slug                      VARCHAR(255) NOT NULL,
    description               TEXT         NULL,
    status                    VARCHAR(20)  NOT NULL DEFAULT 'draft'
                                  CHECK (status IN ('draft', 'active', 'inactive', 'suspended')),
    state_code                CHAR(2)      NOT NULL,
    county                    VARCHAR(100) NOT NULL,
    address_encrypted         TEXT         NULL,  -- encrypted (pgp_sym_encrypt) — physical address, gate road
    total_acres               NUMERIC(10,2) NOT NULL,
    huntable_acres            NUMERIC(10,2) NULL,
    boundary_geospatial_id    UUID         NULL,  -- References DB 13 (Geospatial) property_boundaries.id
    primary_photo_document_id UUID         NULL,  -- References DB 11 (Documents) documents.id
    created_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at                TIMESTAMPTZ  NULL
);

CREATE UNIQUE INDEX uq_properties_slug        ON properties (slug) WHERE deleted_at IS NULL;
CREATE        INDEX idx_properties_owner      ON properties (owner_user_id);
CREATE        INDEX idx_properties_status     ON properties (status);
CREATE        INDEX idx_properties_state      ON properties (state_code);
CREATE        INDEX idx_properties_county     ON properties (state_code, county);
CREATE        INDEX idx_properties_deleted_at ON properties (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_properties_updated_at
    BEFORE UPDATE ON properties
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `address_encrypted` is the physical street address and access road information — not the gate code. Gate codes live in `property_access_info`. Decrypt only via `PropertyService::getAddress($propertyId)`.
- `boundary_geospatial_id` links to the PostGIS polygon in DB 13. Never duplicate geometry data here.
- `huntable_acres` may differ from `total_acres` (farmland, ponds, structures are excluded).
- `status = 'suspended'` is set by staff (e.g., complaint investigation) and overrides all listings.

---

### `property_listings`

A published listing for a property. A property can have multiple listings (one per season, or different listing types simultaneously — e.g., a day-hunt listing alongside an annual lease listing).

```sql
CREATE TABLE property_listings (
    id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id      UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    listing_type     VARCHAR(20)  NOT NULL
                         CHECK (listing_type IN ('annual_lease', 'seasonal_lease', 'day_hunt', 'auction')),
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft'
                         CHECK (status IN ('draft', 'active', 'sold_out', 'expired', 'archived')),
    season_start     DATE         NULL,
    season_end       DATE         NULL,
    min_hunters      SMALLINT     NULL,
    max_hunters      SMALLINT     NOT NULL DEFAULT 1,
    price_per_hunter NUMERIC(10,2) NULL,
    price_total      NUMERIC(10,2) NULL,
    deposit_amount   NUMERIC(10,2) NULL,
    deposit_percent  SMALLINT     NULL CHECK (deposit_percent BETWEEN 0 AND 100),
    auto_renew       BOOLEAN      NOT NULL DEFAULT false,
    visibility       VARCHAR(20)  NOT NULL DEFAULT 'public'
                         CHECK (visibility IN ('public', 'members_only', 'invite_only')),
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ  NULL
);

CREATE INDEX idx_property_listings_property_id ON property_listings (property_id);
CREATE INDEX idx_property_listings_status      ON property_listings (status);
CREATE INDEX idx_property_listings_type        ON property_listings (listing_type);
CREATE INDEX idx_property_listings_season      ON property_listings (season_start, season_end);
CREATE INDEX idx_property_listings_deleted_at  ON property_listings (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_property_listings_updated_at
    BEFORE UPDATE ON property_listings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- Either `price_per_hunter` or `price_total` must be non-null for active listings. The service layer enforces this.
- `deposit_amount` and `deposit_percent` are mutually exclusive — only one should be set. The billing service uses whichever is present.
- `listing_type = 'auction'` will also create a row in `ah_commerce.auction_listings` (DB 6) via `AuctionService`.
- Active listings query: `WHERE status = 'active' AND deleted_at IS NULL`.

---

### `property_photos`

Photos attached to a property. The actual file lives in object storage (Garage / Azure Blob); this table stores metadata.

```sql
CREATE TABLE property_photos (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    document_id UUID        NOT NULL,  -- References DB 11 (Documents) documents.id
    sort_order  SMALLINT    NOT NULL DEFAULT 0,
    caption     VARCHAR(255) NULL,
    tags        JSONB       NOT NULL DEFAULT '[]'::jsonb,
    latitude    NUMERIC(9,6) NULL,
    longitude   NUMERIC(9,6) NULL,
    is_primary  BOOLEAN     NOT NULL DEFAULT false,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ NULL
);

CREATE INDEX idx_property_photos_property_id ON property_photos (property_id);
CREATE INDEX idx_property_photos_sort_order  ON property_photos (property_id, sort_order);
CREATE INDEX idx_property_photos_tags_gin    ON property_photos USING GIN (tags);
```

**Notes:**
- At most one row should have `is_primary = true` per property. Enforced by `PropertyService` — not a DB constraint, to allow for easy reordering.
- `tags` is a JSON array of free-form strings (suggested values defined in `PropertyFormV2::photoTagSuggestions()`), used for gallery filtering.
- `latitude` / `longitude` record where the photo was taken (WGS84) — display only, never used for spatial queries (use DB 13 for that). Auto-extracted from EXIF GPS on upload; editable manually in the admin.
- `document_id` resolves to a URL via `DocumentService::getUrl($documentId)`.
- Photos with `deleted_at IS NOT NULL` are soft-deleted; the underlying file in object storage is retained for 30 days then purged by `PurgeOrphanedDocumentsJob`.

---

### `property_map_images`

Boundary and other map images for a property (topo maps, aerials with boundary lines, stand maps). Files live in object storage via DB 11; this table stores metadata.

```sql
CREATE TABLE property_map_images (
    id          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    document_id UUID         NOT NULL,  -- References DB 11 (Documents) documents.id
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    latitude    NUMERIC(9,6) NULL,
    longitude   NUMERIC(9,6) NULL,
    is_boundary BOOLEAN      NOT NULL DEFAULT false,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ  NULL
);

CREATE INDEX idx_property_map_images_property_id ON property_map_images (property_id);
CREATE INDEX idx_property_map_images_sort_order  ON property_map_images (property_id, sort_order);
```

**Notes:**
- Exactly one live row per property has `is_boundary = true` — the first upload becomes the boundary map automatically. Enforced by `PropertyMapService`, not the DB.
- Only the boundary map is served publicly (`/property-maps/{documentId}` route validates `is_boundary`); other map images stay behind the admin guard.
- Soft delete keeps the underlying DB 11 document live (lease-documents pattern), so restore is lossless and markers survive.

### `property_map_markers`

Admin-placed annotations on a map image — amenities, game locations, stands, access points. Never rendered into the public image.

```sql
CREATE TABLE property_map_markers (
    id           UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    map_image_id UUID          NOT NULL REFERENCES property_map_images (id) ON DELETE CASCADE,
    label        VARCHAR(100)  NOT NULL,
    marker_type  VARCHAR(20)   NOT NULL DEFAULT 'other'
                     CHECK (marker_type IN ('amenity', 'game', 'stand', 'camera', 'access', 'hazard', 'water', 'other')),
    x_percent    NUMERIC(6,3)  NOT NULL CHECK (x_percent >= 0 AND x_percent <= 100),
    y_percent    NUMERIC(6,3)  NOT NULL CHECK (y_percent >= 0 AND y_percent <= 100),
    latitude     NUMERIC(9,6)  NULL,
    longitude    NUMERIC(9,6)  NULL,
    color        VARCHAR(7)    NULL CHECK (color IS NULL OR color ~ '^#[0-9a-fA-F]{6}$'),
    notes        VARCHAR(255)  NULL,
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at   TIMESTAMPTZ   NULL
);

CREATE INDEX idx_property_map_markers_map_image_id ON property_map_markers (map_image_id);
```

**Notes:**
- `x_percent`/`y_percent` anchor the marker to the image (percent from top-left), independent of zoom or image size. `latitude`/`longitude` optionally record the real-world position.

---

### `property_amenities`

Master list of amenities. Seeded at installation; staff can add new ones via the admin backend.

```sql
CREATE TABLE property_amenities (
    id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    category   VARCHAR(20) NOT NULL
                   CHECK (category IN ('accommodation', 'access', 'water', 'stand', 'food_plot', 'other')),
    icon_name  VARCHAR(50) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_property_amenities_name ON property_amenities (name);
CREATE        INDEX idx_property_amenities_cat ON property_amenities (category);
```

**Seed data examples:**

| name | category |
|---|---|
| Hunting cabin | accommodation |
| Electricity | accommodation |
| Running water | accommodation |
| ATV trails | access |
| Paved road access | access |
| Pond / lake | water |
| Creek | water |
| Elevated box blinds | stand |
| Lock-on stands | stand |
| Food plots | food_plot |
| Deer feeders | food_plot |

---

### `property_amenity_listings`

Pivot table linking amenities to specific listings. Notes allow listing-specific context (e.g., "3 box blinds included").

```sql
CREATE TABLE property_amenity_listings (
    listing_id  UUID         NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
    amenity_id  UUID         NOT NULL REFERENCES property_amenities (id) ON DELETE CASCADE,
    notes       VARCHAR(255) NULL,
    PRIMARY KEY (listing_id, amenity_id)
);

CREATE INDEX idx_property_amenity_listings_amenity ON property_amenity_listings (amenity_id);
```

---

### `property_species`

Wildlife species available on a property. Used for search filtering ("Show me properties with whitetail deer and turkey").

```sql
CREATE TABLE property_species (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id  UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    species_code VARCHAR(50) NOT NULL
                     CHECK (species_code IN (
                         'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                         'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                         'rabbit', 'squirrel', 'coyote', 'other'
                     )),
    is_primary   BOOLEAN     NOT NULL DEFAULT false,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_property_species_property_species ON property_species (property_id, species_code);
CREATE        INDEX idx_property_species_property_id     ON property_species (property_id);
CREATE        INDEX idx_property_species_code            ON property_species (species_code);
```

---

### `property_rules`

Landowner-defined rules for the property (no alcohol, no dogs, must pack out all trash, etc.).

```sql
CREATE TABLE property_rules (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    rule_text   TEXT        NOT NULL,
    sort_order  SMALLINT    NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_property_rules_property_id ON property_rules (property_id);

CREATE TRIGGER trg_property_rules_updated_at
    BEFORE UPDATE ON property_rules
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `property_access_info`

Gate codes, wifi passwords, cabin door codes, and turn-by-turn access directions. Encrypted at rest. RLS-enforced — only active lessees for the property may read this row.

```sql
CREATE TABLE property_access_info (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id           UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    access_info_encrypted TEXT        NOT NULL,  -- encrypted (pgp_sym_encrypt) — JSON blob: gate codes, wifi, cabin codes, directions
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by_user_id    UUID        NULL  -- References DB 1 (Identity) users.id
);

CREATE UNIQUE INDEX uq_property_access_info_property ON property_access_info (property_id);
```

**RLS Policy:**
```sql
ALTER TABLE property_access_info ENABLE ROW LEVEL SECURITY;

-- Only the property owner or users with an active lease for this property may read access info.
-- The application service layer enforces this too; RLS is the DB-level backstop.
CREATE POLICY access_info_owner_or_active_lessee ON property_access_info
    FOR SELECT TO ah_app
    USING (
        -- Enforced at service layer; DB-level policy requires staff or owner context
        current_setting('app.current_role') IN ('staff', 'super_admin')
        OR property_id IN (
            SELECT property_id FROM ah_lease.leases  -- cross-DB check not possible in RLS
            -- NOTE: Full lessee check is enforced in PropertyService::getAccessInfo()
            -- RLS here restricts to staff only; service layer grants lessees access
        )
    );
```

**Notes:**
- The RLS policy above is simplified — actual lessee authorization is enforced in `PropertyService::getAccessInfo()` which validates the requesting user has an active `ah_lease.leases` record for this property. The DB-level RLS restricts unauthenticated access.
- `access_info_encrypted` decrypts to a JSON object: `{"gate_code": "1234#", "wifi_ssid": "HuntCabin", "wifi_password": "...", "directions": "...", "cabin_code": "..."}`
- Never read the raw column and display it. Always use `PropertyService::getAccessInfo($propertyId)`.

---

### `property_availability`

Blocked or booked date ranges for a listing. Prevents double-booking and surfaces available windows on the calendar.

```sql
CREATE TABLE property_availability (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    listing_id  UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
    date_start  DATE        NOT NULL,
    date_end    DATE        NOT NULL,
    reason      VARCHAR(20) NOT NULL DEFAULT 'booked'
                    CHECK (reason IN ('booked', 'blocked', 'maintenance')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_property_availability_dates CHECK (date_end >= date_start)
);

CREATE INDEX idx_property_availability_listing_id ON property_availability (listing_id);
CREATE INDEX idx_property_availability_dates      ON property_availability (listing_id, date_start, date_end);
```

---

### `saved_properties`

Hunters save listings to a wishlist for later. One row per (user, listing) pair.

```sql
CREATE TABLE saved_properties (
    id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    listing_id UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_saved_properties_user_listing ON saved_properties (user_id, listing_id);
CREATE        INDEX idx_saved_properties_user_id     ON saved_properties (user_id);
CREATE        INDEX idx_saved_properties_listing_id  ON saved_properties (listing_id);
```

---

### `property_managers`

Users who manage or operate a property on behalf of the owner. Tracks property managers, ranch foremen, and co-owners. Management authority at the property level cascades to all listings and leases on that property — `PropertyService::canManageProperty($userId, $propertyId)` is the single gate.

```sql
CREATE TABLE property_managers (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id         UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
    user_id             UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    role                VARCHAR(20) NOT NULL
                            CHECK (role IN ('owner', 'co_owner', 'manager', 'operator')),
    granted_by_user_id  UUID        NOT NULL,  -- References DB 1 (Identity) users.id — must be owner or staff
    granted_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at          TIMESTAMPTZ NULL,      -- soft revocation; NULL = currently active
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_property_managers_active
    ON property_managers (property_id, user_id)
    WHERE revoked_at IS NULL;
CREATE INDEX idx_property_managers_property_id ON property_managers (property_id);
CREATE INDEX idx_property_managers_user_id     ON property_managers (user_id);
CREATE INDEX idx_property_managers_active      ON property_managers (property_id) WHERE revoked_at IS NULL;
```

**Role semantics:**
- `owner` — granted ownership of the property via admin assignment (distinct from `properties.owner_user_id` direct ownership). Displayed in the user's "Properties Owned" section, not in the manager/operator section. Full ownership-equivalent access.
- `co_owner` — full access equivalent to owner: create/edit listings, review applications, manage active leases, view gate codes, receive payouts
- `manager` — edit listings, review and approve applications, manage active leases, view gate codes; cannot create listings or modify payout settings
- `operator` — field-level access: check hunters in/out, view lease roster, view gate codes; cannot review applications or manage lease terms

**Display rules (admin UI):**
- `owner` grants appear in the user's **Properties Owned** section (merged with `properties.owner_user_id` direct ownership)
- `co_owner`, `manager`, `operator` grants appear in the user's **Property Manager / Operator Roles** section
- Both sections deduplicate by `property_id` to prevent double-listing

**Notes:**
- Only the property owner or a `super_admin`/`global_admin`/`property_admin` may grant or revoke property manager access via the admin Managers tab on the property edit page.
- `revoked_at` is set on revocation — never hard-delete. Historical grants are preserved for audit purposes.
- The unique partial index on `(property_id, user_id) WHERE revoked_at IS NULL` prevents duplicate active grants while allowing a user to be re-granted access after revocation.
- When checking management authority, always use `PropertyService::canManageProperty($userId, $propertyId)` — never query this table directly from a controller or Filament page.

---

### `property_views`

Raw view events for analytics. Populated on every listing page view. ETL jobs aggregate this into DB 8. Do not query this table for reporting — use DB 8.

```sql
CREATE TABLE property_views (
    id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    listing_id UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
    user_id    UUID        NULL,  -- References DB 1 (Identity) users.id — null for anonymous
    ip_address INET        NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only — no updated_at, no deleted_at
CREATE INDEX idx_property_views_listing_id ON property_views (listing_id);
CREATE INDEX idx_property_views_created_at ON property_views (created_at);
CREATE INDEX idx_property_views_user_id    ON property_views (user_id) WHERE user_id IS NOT NULL;
```

**Notes:**
- High insert volume — consider table partitioning by `created_at` month if row count exceeds 50M.
- ETL job `AggregatePropertyViewsJob` reads from this table via `analytics_etl` and writes aggregates to DB 8.

---

## Eloquent Models

### `App\Models\Property\Property`

```php
<?php

namespace App\Models\Property;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $connection = 'property';
    protected $table      = 'properties';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'owner_user_id',
        'title',
        'slug',
        'description',
        'status',
        'state_code',
        'county',
        'total_acres',
        'huntable_acres',
        'boundary_geospatial_id',
        'primary_photo_document_id',
    ];

    protected $hidden = ['address_encrypted'];

    protected function casts(): array
    {
        return [
            'total_acres'     => 'decimal:2',
            'huntable_acres'  => 'decimal:2',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    public function listings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyListing::class, 'property_id');
    }

    public function photos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyPhoto::class, 'property_id')
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order');
    }

    public function species(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertySpecies::class, 'property_id');
    }

    public function rules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyRule::class, 'property_id')
                    ->orderBy('sort_order');
    }

    // Cross-DB: resolved via UserService
    public function getOwner(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->find($this->owner_user_id);
    }

    // Cross-DB: resolved via GeospatialService
    public function getBoundary(): ?\App\Models\Geospatial\PropertyBoundary
    {
        if (! $this->boundary_geospatial_id) {
            return null;
        }
        return app(\App\Services\Property\GeospatialService::class)
            ->getBoundary($this->boundary_geospatial_id);
    }
}
```

### `App\Models\Property\PropertyListing`

```php
protected $connection = 'property';
protected $table      = 'property_listings';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'price_per_hunter' => 'decimal:2',
        'price_total'      => 'decimal:2',
        'deposit_amount'   => 'decimal:2',
        'auto_renew'       => 'boolean',
        'season_start'     => 'date',
        'season_end'       => 'date',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];
}
```

### `App\Models\Property\PropertyPhoto`

```php
protected $connection = 'property';
protected $table      = 'property_photos';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'is_primary' => 'boolean',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}

// Cross-DB: resolve document URL via DocumentService
public function getUrl(): string
{
    return app(\App\Services\Documents\DocumentService::class)->getUrl($this->document_id);
}
```

### `App\Models\Property\PropertyAccessInfo`

```php
protected $connection = 'property';
protected $table      = 'property_access_info';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected $hidden     = ['access_info_encrypted'];

// Never expose this model's encrypted field directly.
// Always use PropertyService::getAccessInfo($propertyId).
```

### `App\Models\Property\PropertyManager`

```php
protected $connection = 'property';
protected $table      = 'property_managers';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected $fillable = [
    'property_id',
    'user_id',
    'role',
    'granted_by_user_id',
    'granted_at',
    'revoked_at',
];

protected function casts(): array
{
    return [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}

public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
{
    return $query->whereNull('revoked_at');
}

// Cross-DB: resolved via UserService
public function getUser(): ?\App\Models\Identity\User
{
    return app(\App\Services\Identity\UserService::class)->find($this->user_id);
}
```

---

## Service Notes

- **`PropertyService`** — primary service for property and listing CRUD, access info decryption, lessee authorization checks, and property manager grants. At `App\Services\Property\PropertyService`. Caches listing data in Valkey Cluster 2 with key `listing:{id}`.
- **`PropertyService::canManageProperty($userId, $propertyId)`** — the single authority gate. Returns `true` if the user is the owner (`properties.owner_user_id`) OR has an active `property_managers` row for the property. All lease-level management checks in `LeaseService` call this before granting access.
- **`GeospatialService`** — resolves `boundary_geospatial_id` to PostGIS geometry, handles map query integration. At `App\Services\Property\GeospatialService`.
- Search queries that filter by species, state, county, acreage, price range, and availability should always use the `property_read` connection.
- Write operations (create property, update listing status, upload photo) must use the `property` connection.
- Invalidate `listing:{id}` in Valkey after any update to `property_listings`, `property_photos`, `property_amenity_listings`, or `property_species`.
