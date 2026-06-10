# DB 6 — Commerce & Marketplace

**Connection:** `commerce`
**Database:** `ah_commerce`
**App User:** `ah_app`
**Server:** Burst-capable standard PostgreSQL — sized for auction traffic spikes
**Encryption Key:** Key F — rotated annually via Azure Key Vault
**Extensions:** `uuid-ossp`
**RLS Enabled:** No — access control enforced at the service layer
**Feature Flags:** `auction_module`, `consulting_marketplace`, `outfitter_booking`, `equipment_marketplace`, `lease_wanted_board`

This database supports the marketplace and commerce features beyond the core lease transaction: competitive auctions for premium leases, a peer-to-peer equipment marketplace, outfitter-guided hunt bookings, consulting engagements, and the "lease wanted" board where hunters post what they're looking for.

All features in this database are behind feature flags in DB 12. Always call `feature('auction_module')` (etc.) before routing to commerce endpoints.

Live auction bid state (current bid, countdown timer, bid lock) is managed in **Valkey Cluster 4 (`auction`)** for sub-millisecond performance. This database stores the durable record of completed bids and auction outcomes — not the live hot state.

---

## Tables

### `auction_listings`

A property listing that is being auctioned competitively. Created when `listing_type = 'auction'` in DB 2. One `auction_listing` record per `property_listing`.

```sql
CREATE TABLE auction_listings (
    id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    listing_id              UUID        NOT NULL,  -- References DB 2 (Property) property_listings.id
    status                  VARCHAR(15) NOT NULL DEFAULT 'scheduled'
                                CHECK (status IN ('scheduled', 'active', 'ended', 'cancelled')),
    reserve_price_cents     BIGINT      NULL,   -- minimum acceptable winning bid (hidden from bidders)
    opening_bid_cents       BIGINT      NOT NULL,
    current_bid_cents       BIGINT      NULL,   -- null until first bid is placed
    current_bidder_user_id  UUID        NULL,  -- References DB 1 (Identity) users.id
    bid_count               SMALLINT    NOT NULL DEFAULT 0,
    starts_at               TIMESTAMPTZ NOT NULL,
    ends_at                 TIMESTAMPTZ NOT NULL,
    auto_extend_minutes     SMALLINT    NOT NULL DEFAULT 5,
        -- if a bid is placed within this many minutes of ends_at, extend by auto_extend_minutes
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ NULL,

    CONSTRAINT chk_auction_listings_dates  CHECK (ends_at > starts_at),
    CONSTRAINT chk_auction_listings_prices CHECK (opening_bid_cents > 0)
);

CREATE UNIQUE INDEX uq_auction_listings_listing_id ON auction_listings (listing_id) WHERE deleted_at IS NULL;
CREATE        INDEX idx_auction_listings_status    ON auction_listings (status);
CREATE        INDEX idx_auction_listings_starts_at ON auction_listings (starts_at);
CREATE        INDEX idx_auction_listings_ends_at   ON auction_listings (ends_at);

CREATE TRIGGER trg_auction_listings_updated_at
    BEFORE UPDATE ON auction_listings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- **Live bid state is in Valkey Cluster 4**, not this table. The Valkey key `auction:{id}:state` holds the JSON object `{current_bid_cents, current_bidder_user_id, ends_at, bid_count}`. This table is the durable record updated after each bid confirmation.
- `reserve_price_cents` is never returned to bidders. Only `AuctionService` reads it internally to determine if the reserve has been met when an auction ends.
- `auto_extend_minutes` implements "soft close" anti-sniping: any bid in the last N minutes extends the auction.
- Status transitions: `scheduled` → `active` (via scheduler job at `starts_at`) → `ended` (at `ends_at`) or `cancelled` (by admin/landowner).

---

### `auction_bids`

Every bid placed in an auction. **Immutable once created** — bids are never updated or deleted. The winning bid is determined at auction end, not in real time.

```sql
CREATE TABLE auction_bids (
    id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    auction_id        UUID        NOT NULL REFERENCES auction_listings (id),
    bidder_user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    amount_cents      BIGINT      NOT NULL,
    is_winning        BOOLEAN     NOT NULL DEFAULT false,
    is_auto_bid       BOOLEAN     NOT NULL DEFAULT false,
    max_auto_bid_cents BIGINT     NULL,  -- auto-bid ceiling; encrypted in production via Valkey (not stored here in plaintext)
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- NO updated_at — bids are immutable once created
    -- NO deleted_at — bids are permanent auction integrity records
);

CREATE INDEX idx_auction_bids_auction_id      ON auction_bids (auction_id);
CREATE INDEX idx_auction_bids_bidder_user_id  ON auction_bids (bidder_user_id);
CREATE INDEX idx_auction_bids_amount_cents    ON auction_bids (auction_id, amount_cents DESC);
CREATE INDEX idx_auction_bids_created_at      ON auction_bids (auction_id, created_at DESC);
```

**Notes:**
- Never call `update()` or `delete()` on `AuctionBid` model instances. The model overrides both to throw.
- `is_winning` is `false` on all bids until the auction ends. `AuctionService::settleAuction()` sets `is_winning = true` on the highest qualifying bid (at or above reserve).
- `max_auto_bid_cents` is the bidder's proxy bid ceiling. **In production, this is stored in Valkey Cluster 4 (`auction:{id}:autobid:{user_id}`) and is NOT stored in plaintext in this column.** The column exists for audit trail but is null in the live system — the Valkey value is the source of truth during the auction.
- All bids are validated against the minimum bid increment (configurable in DB 12 platform settings) before insertion.

---

### `auction_watchers`

Users who are watching an auction but have not yet placed a bid. Used for "You've been outbid" and "Auction ending soon" notifications.

```sql
CREATE TABLE auction_watchers (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    auction_id          UUID        NOT NULL REFERENCES auction_listings (id) ON DELETE CASCADE,
    user_id             UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    notify_on_outbid    BOOLEAN     NOT NULL DEFAULT true,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_auction_watchers_auction_user ON auction_watchers (auction_id, user_id);
CREATE        INDEX idx_auction_watchers_user_id     ON auction_watchers (user_id);
```

---

### `equipment_listings`

Peer-to-peer marketplace for hunting gear — stands, feeders, optics, firearms (where legally permitted), boats, ATVs, etc.

Feature flag: `equipment_marketplace`

```sql
CREATE TABLE equipment_listings (
    id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    seller_user_id  UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    title           VARCHAR(255) NOT NULL,
    description     TEXT        NULL,
    category        VARCHAR(50) NOT NULL,
        -- 'stands', 'feeders', 'optics', 'firearms', 'archery', 'clothing', 'calls',
        -- 'trail_cameras', 'vehicles_atv', 'boats', 'processing', 'other'
    condition       VARCHAR(10) NOT NULL
                        CHECK (condition IN ('new', 'like_new', 'good', 'fair', 'poor')),
    price_cents     BIGINT      NOT NULL,
    is_negotiable   BOOLEAN     NOT NULL DEFAULT false,
    status          VARCHAR(10) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active', 'sold', 'removed')),
    state_code      CHAR(2)     NULL,
    photos          JSONB       NOT NULL DEFAULT '[]',  -- array of document_ids from DB 11
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ NULL
);

CREATE INDEX idx_equipment_listings_seller_user_id ON equipment_listings (seller_user_id);
CREATE INDEX idx_equipment_listings_status         ON equipment_listings (status);
CREATE INDEX idx_equipment_listings_category       ON equipment_listings (category);
CREATE INDEX idx_equipment_listings_state_code     ON equipment_listings (state_code);
CREATE INDEX idx_equipment_listings_price_cents    ON equipment_listings (price_cents);
CREATE INDEX idx_equipment_listings_deleted_at     ON equipment_listings (deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX idx_equipment_listings_photos_gin     ON equipment_listings USING GIN (photos);

CREATE TRIGGER trg_equipment_listings_updated_at
    BEFORE UPDATE ON equipment_listings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `outfitter_services`

Guided hunting experiences offered by verified outfitters. An outfitter may be associated with a specific property (via `property_id`) or operate on their own land not listed on the platform.

Feature flag: `outfitter_booking`

```sql
CREATE TABLE outfitter_services (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    outfitter_user_id     UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    property_id           UUID        NULL,  -- References DB 2 (Property) properties.id — nullable
    title                 VARCHAR(255) NOT NULL,
    description           TEXT        NULL,
    species_codes         JSONB       NOT NULL DEFAULT '[]',
        -- array of species_code strings: ["whitetail_deer", "turkey"]
    duration_days         SMALLINT    NOT NULL DEFAULT 1,
    price_per_person_cents BIGINT     NOT NULL,
    max_guests            SMALLINT    NOT NULL DEFAULT 1,
    status                VARCHAR(10) NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('active', 'inactive', 'draft')),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at            TIMESTAMPTZ NULL
);

CREATE INDEX idx_outfitter_services_outfitter ON outfitter_services (outfitter_user_id);
CREATE INDEX idx_outfitter_services_status    ON outfitter_services (status);
CREATE INDEX idx_outfitter_services_property  ON outfitter_services (property_id) WHERE property_id IS NOT NULL;
CREATE INDEX idx_outfitter_services_species   ON outfitter_services USING GIN (species_codes);
CREATE INDEX idx_outfitter_services_deleted   ON outfitter_services (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_outfitter_services_updated_at
    BEFORE UPDATE ON outfitter_services
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `outfitter_bookings`

A hunter's booking of an outfitter service. Payment is handled via DB 4 (`invoice_id` cross-DB reference).

Feature flag: `outfitter_booking`

```sql
CREATE TABLE outfitter_bookings (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    service_id       UUID        NOT NULL REFERENCES outfitter_services (id),
    hunter_user_id   UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    status           VARCHAR(15) NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending', 'confirmed', 'cancelled', 'completed')),
    start_date       DATE        NOT NULL,
    end_date         DATE        NOT NULL,
    party_size       SMALLINT    NOT NULL DEFAULT 1,
    total_price_cents BIGINT     NOT NULL,
    invoice_id       UUID        NULL,  -- References DB 4 (Billing) invoices.id
    notes            TEXT        NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ NULL,

    CONSTRAINT chk_outfitter_bookings_dates CHECK (end_date >= start_date)
);

CREATE INDEX idx_outfitter_bookings_service_id     ON outfitter_bookings (service_id);
CREATE INDEX idx_outfitter_bookings_hunter_user_id ON outfitter_bookings (hunter_user_id);
CREATE INDEX idx_outfitter_bookings_status         ON outfitter_bookings (status);
CREATE INDEX idx_outfitter_bookings_dates          ON outfitter_bookings (start_date, end_date);
CREATE INDEX idx_outfitter_bookings_deleted        ON outfitter_bookings (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_outfitter_bookings_updated_at
    BEFORE UPDATE ON outfitter_bookings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `consulting_requests`

A landowner or hunter hiring a hunting consultant for land assessment, habitat improvement planning, trophy scoring, or hunting strategy. Payment is invoiced via DB 4.

Feature flag: `consulting_marketplace`

```sql
CREATE TABLE consulting_requests (
    id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    client_user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    consultant_user_id UUID       NOT NULL,  -- References DB 1 (Identity) users.id
    property_id       UUID        NULL,  -- References DB 2 (Property) properties.id — nullable
    status            VARCHAR(15) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'accepted', 'in_progress', 'completed', 'cancelled')),
    service_type      VARCHAR(50) NOT NULL,
        -- 'land_assessment', 'habitat_improvement', 'trophy_scoring', 'hunting_strategy', 'other'
    message           TEXT        NULL,
    agreed_rate_cents BIGINT      NULL,   -- set when accepted
    invoice_id        UUID        NULL,  -- References DB 4 (Billing) invoices.id
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at        TIMESTAMPTZ NULL
);

CREATE INDEX idx_consulting_requests_client     ON consulting_requests (client_user_id);
CREATE INDEX idx_consulting_requests_consultant ON consulting_requests (consultant_user_id);
CREATE INDEX idx_consulting_requests_status     ON consulting_requests (status);
CREATE INDEX idx_consulting_requests_deleted    ON consulting_requests (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_consulting_requests_updated_at
    BEFORE UPDATE ON consulting_requests
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `lease_wanted_posts`

Hunters post their requirements to find available properties. Landowners browse these and can reach out directly.

Feature flag: `lease_wanted_board`

```sql
CREATE TABLE lease_wanted_posts (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id          UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    state_code       CHAR(2)     NOT NULL,
    county           VARCHAR(100) NULL,
    species_codes    JSONB       NOT NULL DEFAULT '[]',
    max_budget_cents BIGINT      NULL,
    party_size       SMALLINT    NOT NULL DEFAULT 1,
    desired_start    DATE        NULL,
    desired_end      DATE        NULL,
    description      TEXT        NULL,
    status           VARCHAR(15) NOT NULL DEFAULT 'active'
                         CHECK (status IN ('active', 'fulfilled', 'expired')),
    expires_at       DATE        NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at       TIMESTAMPTZ NULL
);

CREATE INDEX idx_lease_wanted_posts_user_id     ON lease_wanted_posts (user_id);
CREATE INDEX idx_lease_wanted_posts_state_code  ON lease_wanted_posts (state_code);
CREATE INDEX idx_lease_wanted_posts_status      ON lease_wanted_posts (status);
CREATE INDEX idx_lease_wanted_posts_expires_at  ON lease_wanted_posts (expires_at);
CREATE INDEX idx_lease_wanted_posts_species_gin ON lease_wanted_posts USING GIN (species_codes);
CREATE INDEX idx_lease_wanted_posts_deleted     ON lease_wanted_posts (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_lease_wanted_posts_updated_at
    BEFORE UPDATE ON lease_wanted_posts
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

## Eloquent Models

### `App\Models\Commerce\AuctionListing`

```php
<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuctionListing extends Model
{
    use SoftDeletes;

    protected $connection = 'commerce';
    protected $table      = 'auction_listings';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'listing_id',
        'status',
        'reserve_price_cents',
        'opening_bid_cents',
        'current_bid_cents',
        'current_bidder_user_id',
        'bid_count',
        'starts_at',
        'ends_at',
        'auto_extend_minutes',
    ];

    protected $hidden = ['reserve_price_cents'];  // Never expose to bidders

    protected function casts(): array
    {
        return [
            'starts_at'   => 'datetime',
            'ends_at'     => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }

    public function bids(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AuctionBid::class, 'auction_id')->orderByDesc('created_at');
    }
}
```

### `App\Models\Commerce\AuctionBid`

```php
<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;

class AuctionBid extends Model
{
    protected $connection = 'commerce';
    protected $table      = 'auction_bids';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $hidden = ['max_auto_bid_cents'];  // Never expose auto-bid ceiling

    protected function casts(): array
    {
        return [
            'is_winning'  => 'boolean',
            'is_auto_bid' => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    // Bids are immutable auction integrity records
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Auction bids are immutable and cannot be updated.');
    }

    public function delete(): bool
    {
        throw new \LogicException('Auction bids are permanent records and cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('Auction bids are permanent records and cannot be deleted.');
    }
}
```

### `App\Models\Commerce\OutfitterBooking`

```php
protected $connection = 'commerce';
protected $table      = 'outfitter_bookings';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];
}
```

### `App\Models\Commerce\EquipmentListing`

```php
protected $connection = 'commerce';
protected $table      = 'equipment_listings';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'photos'        => 'array',
        'is_negotiable' => 'boolean',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];
}
```

---

## Service Notes

- **`AuctionService`** — manages auction lifecycle, bid validation, Valkey hot state sync, auto-bid proxy logic, reserve check at settlement, and winner notification. At `App\Services\Commerce\AuctionService`. Uses Valkey Cluster 4 (`auction`) for live state.
- **`MarketplaceService`** — equipment listing CRUD, search, and contact flow. At `App\Services\Commerce\MarketplaceService`.
- **`OutfitterService`** — outfitter service creation, availability checking, booking flow, and invoice creation via `BillingService`. At `App\Services\Commerce\OutfitterService`.
- **`ConsultingService`** — consulting request flow, acceptance, invoicing. At `App\Services\Commerce\ConsultingService`.
- **Queue jobs:** `ActivateAuctionJob` (scheduled at `starts_at`), `CloseAuctionJob` (scheduled at `ends_at`), `NotifyAuctionWinnersJob`, `SendOutbidNotificationJob`, `ExpireLeaseWantedPostsJob`.
- Always check `feature('auction_module')` before routing to auction endpoints. Gate `outfitter_booking`, `equipment_marketplace`, `consulting_marketplace`, and `lease_wanted_board` similarly.
- Auction bid placement must be serialized through `AuctionService::placeBid()` which uses Valkey Lua scripting to atomically validate and place bids without race conditions.
