# DB 3 — Lease & Contract

**Connection:** `lease`
**Database:** `ah_lease`
**App User:** `ah_app`
**Server:** Dedicated high-security PostgreSQL instance — separate from property DB to isolate legal contract data
**Encryption Key:** Key C — rotated annually via Azure Key Vault
**Extensions:** `pgcrypto`, `uuid-ossp`
**RLS Enabled:** Yes — on `leases`, `lease_hunters`, `check_ins`, `lease_notes`

This database governs the entire lease lifecycle: applications from hunters, approved leases, club memberships, hunter authorization under a lease, check-in/check-out, e-signature tracking, and renewal offers. It is the most legally sensitive general-purpose database in the system.

All references to properties, listings, and users are cross-DB UUID columns — never enforced foreign keys across database boundaries.

**Immutability exception:** `signature_events` rows are never deleted. They are the legally admissible e-signature audit trail. The model does not extend `ImmutableModel` (that pattern is reserved for DB 9) but `delete()` and `forceDelete()` are overridden to throw in the model class.

---

## Tables

### `lease_applications`

A hunter or club submits an application to lease a property listing. One application per (listing, applicant) pair in most cases, but a hunter may re-apply after rejection or withdrawal.

```sql
CREATE TABLE lease_applications (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    listing_id          UUID        NOT NULL,  -- References DB 2 (Property) property_listings.id
    applicant_user_id   UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    application_type    VARCHAR(20) NOT NULL DEFAULT 'individual'
                            CHECK (application_type IN ('individual', 'club')),
    status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                            CHECK (status IN ('pending', 'under_review', 'approved', 'rejected', 'withdrawn', 'expired')),
    message             TEXT        NULL,      -- encrypted (pgp_sym_encrypt via HasEncryptedFields)
    desired_hunters     SMALLINT    NULL,
    proposed_start      DATE        NULL,
    proposed_end        DATE        NULL,
    reviewed_by_user_id UUID        NULL,  -- References DB 1 (Identity) users.id
    reviewed_at         TIMESTAMPTZ NULL,
    rejection_reason    TEXT        NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ NULL
);

CREATE INDEX idx_lease_applications_listing_id        ON lease_applications (listing_id);
CREATE INDEX idx_lease_applications_applicant_user_id ON lease_applications (applicant_user_id);
CREATE INDEX idx_lease_applications_status            ON lease_applications (status);
CREATE INDEX idx_lease_applications_deleted_at        ON lease_applications (deleted_at)
    WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_lease_applications_updated_at
    BEFORE UPDATE ON lease_applications
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `listing_id` resolves to `ah_property.property_listings.id` — never join directly. Use `ApplicationService::getListingDetails($listingId)`.
- `application_type = 'club'` means a club (via `club_id` in application metadata within `message` JSON or a separate `club_leases` link) is applying. Clubs must be verified and in good standing.
- Status transitions: `pending` → `under_review` → `approved` | `rejected` | `expired`. Approved applications trigger `LeaseActivationJob`.
- `message` is **encrypted at rest** via `pgp_sym_encrypt` (Key C). Read/write through `LeaseApplication` model only — it uses the `HasEncryptedFields` trait. Never read the raw column and display it.

---

### `lease_application_hunters`

Per-hunter PII submitted as part of a lease application before the hunter has a platform account. Once a lease is active, hunters are also listed in `lease_hunters` (which links to DB 1 user records). This table holds the raw application-time data.

**High sensitivity — 12 of the 18 columns are encrypted at rest.**

```sql
CREATE TABLE lease_application_hunters (
    id                              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    application_id                  UUID        NOT NULL REFERENCES lease_applications (id) ON DELETE CASCADE,

    -- Identity — plaintext for admin search / minor detection
    first_name                      VARCHAR(80)  NOT NULL,
    last_name                       VARCHAR(80)  NOT NULL,
    date_of_birth                   DATE         NULL,   -- plaintext: used for minor detection

    -- Contact — encrypted
    email                           TEXT         NULL,   -- encrypted
    home_phone                      TEXT         NULL,   -- encrypted
    cell_phone                      TEXT         NULL,   -- encrypted

    -- Address — encrypted (city/state/zip split so state+zip remain queryable)
    address_line1                   TEXT         NULL,   -- encrypted
    address_line2                   TEXT         NULL,   -- encrypted
    city                            TEXT         NULL,   -- encrypted
    state_code                      VARCHAR(2)   NULL,   -- plaintext: state-level aggregation
    zip_code                        VARCHAR(10)  NULL,   -- plaintext: regional queries

    -- Emergency contact — encrypted
    emergency_contact_name          TEXT         NULL,   -- encrypted
    emergency_contact_phone         TEXT         NULL,   -- encrypted
    emergency_contact_relationship  TEXT         NULL,   -- encrypted

    -- Medical / safety — encrypted
    medical_conditions              TEXT         NULL,   -- encrypted

    -- Licensing — encrypted IDs, plaintext metadata
    dl_number                       TEXT         NULL,   -- encrypted
    dl_state                        VARCHAR(2)   NULL,   -- plaintext
    dl_expiry                       DATE         NULL,   -- plaintext
    hunting_license_number          TEXT         NULL,   -- encrypted
    hunting_license_state           VARCHAR(2)   NULL,   -- plaintext
    hunting_license_expiry          DATE         NULL,   -- plaintext

    -- Flags
    is_minor                        BOOLEAN      NOT NULL DEFAULT false,
    guardian_consent_obtained       BOOLEAN      NOT NULL DEFAULT false,
    background_check_consented      BOOLEAN      NOT NULL DEFAULT false,

    created_at                      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at                      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_lah_application_id ON lease_application_hunters (application_id);

CREATE TRIGGER trg_lah_updated_at
    BEFORE UPDATE ON lease_application_hunters
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Encrypted fields** (read/write through `LeaseApplicationHunter` model using `HasEncryptedFields` trait):
`email`, `home_phone`, `cell_phone`, `address_line1`, `address_line2`, `city`,
`emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship`,
`medical_conditions`, `dl_number`, `hunting_license_number`

**Plaintext fields** (intentionally left unencrypted for operational reasons):
- `first_name`, `last_name` — needed for admin search and display
- `date_of_birth` — needed for minor detection logic in `ApplicationService`
- `state_code`, `zip_code` — regional analytics
- `dl_state`, `dl_expiry`, `hunting_license_state`, `hunting_license_expiry` — metadata only, no PII value alone
- Boolean flags — no PII

**Notes:**
- Never log any encrypted field values, even after decryption.
- `is_minor` is set by `ApplicationService` based on `date_of_birth` — `guardian_consent_obtained` must be `true` before the application can be submitted if `is_minor = true`.
- This table has **no** RLS policy — access is controlled at the service layer. Only `ApplicationService` and `LeaseService` read from it.

---

### `leases`

An active lease record created when an application is approved and all required signatures are collected. This is the central record that authorizes hunter access.

```sql
CREATE TABLE leases (
    id                 UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    application_id     UUID         NOT NULL REFERENCES lease_applications (id),
    property_id        UUID         NOT NULL,  -- References DB 2 (Property) properties.id
    listing_id         UUID         NOT NULL,  -- References DB 2 (Property) property_listings.id
    lessee_user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id
    lessor_user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id (property owner)
    status             VARCHAR(25)  NOT NULL DEFAULT 'pending_signatures'
                           CHECK (status IN ('pending_signatures', 'active', 'expired', 'terminated', 'cancelled')),
    start_date         DATE         NOT NULL,
    end_date           DATE         NOT NULL,
    total_price        NUMERIC(10,2) NOT NULL,
    deposit_paid       NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    auto_renew         BOOLEAN      NOT NULL DEFAULT false,
    terminated_at      TIMESTAMPTZ  NULL,
    termination_reason TEXT         NULL,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at         TIMESTAMPTZ  NULL,

    CONSTRAINT chk_leases_dates CHECK (end_date > start_date)
);

CREATE UNIQUE INDEX uq_leases_application_id     ON leases (application_id);
CREATE        INDEX idx_leases_property_id       ON leases (property_id);
CREATE        INDEX idx_leases_listing_id        ON leases (listing_id);
CREATE        INDEX idx_leases_lessee_user_id    ON leases (lessee_user_id);
CREATE        INDEX idx_leases_lessor_user_id    ON leases (lessor_user_id);
CREATE        INDEX idx_leases_status            ON leases (status);
CREATE        INDEX idx_leases_dates             ON leases (start_date, end_date);
CREATE        INDEX idx_leases_deleted_at        ON leases (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_leases_updated_at
    BEFORE UPDATE ON leases
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE leases ENABLE ROW LEVEL SECURITY;

CREATE POLICY leases_parties_and_staff ON leases
    FOR SELECT TO ah_app
    USING (
        lessee_user_id = current_setting('app.current_user_id')::UUID
        OR lessor_user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- Status `pending_signatures` means the lease document has been sent for e-signature but not all parties have signed.
- Status transitions to `active` when `EsignatureService` confirms all signatures via Dropbox Sign webhook.
- `terminated_at` is set when a landlord or staff terminates early. `termination_reason` is required in that case.
- A lease with `auto_renew = true` triggers `CreateLeaseRenewalOfferJob` 60 days before `end_date`.

---

### `lease_hunters`

All hunters authorized under a lease. The primary lessee (from `leases.lessee_user_id`) is also listed here as `role = 'primary'`. Additional hunters (guests, club members) are added by the primary lessee.

```sql
CREATE TABLE lease_hunters (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id    UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    user_id     UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    role        VARCHAR(10) NOT NULL DEFAULT 'member'
                    CHECK (role IN ('primary', 'guest', 'member')),
    is_approved BOOLEAN     NOT NULL DEFAULT false,
    approved_at TIMESTAMPTZ NULL,
    invited_at  TIMESTAMPTZ NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_lease_hunters_lease_user ON lease_hunters (lease_id, user_id) WHERE deleted_at IS NULL;
CREATE        INDEX idx_lease_hunters_lease_id  ON lease_hunters (lease_id);
CREATE        INDEX idx_lease_hunters_user_id   ON lease_hunters (user_id);

CREATE TRIGGER trg_lease_hunters_updated_at
    BEFORE UPDATE ON lease_hunters
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE lease_hunters ENABLE ROW LEVEL SECURITY;

CREATE POLICY lease_hunters_self_and_lessor ON lease_hunters
    FOR SELECT TO ah_app
    USING (
        user_id = current_setting('app.current_user_id')::UUID
        OR lease_id IN (
            SELECT id FROM leases
            WHERE lessor_user_id = current_setting('app.current_user_id')::UUID
        )
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `is_approved` for `role = 'guest'` is set by the landowner after reviewing the guest's trust score and background check.
- The `max_hunters` limit on the listing is enforced in `LeaseService` before adding a hunter — not a DB constraint.
- Removing a hunter soft-deletes the row. Hard deletes are never performed.

---

### `clubs`

A club is a group entity that can hold leases collectively and manage memberships. Clubs are created by a landowner or hunter account and must be verified before applying for leases.

```sql
CREATE TABLE clubs (
    id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    owner_user_id   UUID         NOT NULL,  -- References DB 1 (Identity) users.id
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(150) NOT NULL,
    description     TEXT         NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active', 'inactive', 'suspended')),
    max_members     SMALLINT     NULL,
    membership_fee  NUMERIC(10,2) NULL,
    is_public       BOOLEAN      NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ  NULL
);

CREATE UNIQUE INDEX uq_clubs_slug    ON clubs (slug) WHERE deleted_at IS NULL;
CREATE        INDEX idx_clubs_owner  ON clubs (owner_user_id);
CREATE        INDEX idx_clubs_status ON clubs (status);

CREATE TRIGGER trg_clubs_updated_at
    BEFORE UPDATE ON clubs
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `club_members`

Membership roster for each club.

```sql
CREATE TABLE club_members (
    id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    club_id    UUID        NOT NULL REFERENCES clubs (id) ON DELETE CASCADE,
    user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    role       VARCHAR(10) NOT NULL DEFAULT 'member'
                   CHECK (role IN ('owner', 'admin', 'member')),
    status     VARCHAR(15) NOT NULL DEFAULT 'active'
                   CHECK (status IN ('active', 'invited', 'suspended')),
    joined_at  TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_club_members_club_user ON club_members (club_id, user_id) WHERE deleted_at IS NULL;
CREATE        INDEX idx_club_members_club_id  ON club_members (club_id);
CREATE        INDEX idx_club_members_user_id  ON club_members (user_id);

CREATE TRIGGER trg_club_members_updated_at
    BEFORE UPDATE ON club_members
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `club_leases`

Links a club to a lease. Allows querying all leases held by a club.

```sql
CREATE TABLE club_leases (
    id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    club_id    UUID        NOT NULL REFERENCES clubs (id) ON DELETE CASCADE,
    lease_id   UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_club_leases_club_lease ON club_leases (club_id, lease_id);
CREATE        INDEX idx_club_leases_lease_id  ON club_leases (lease_id);
```

---

### `check_ins`

Hunter check-in/check-out events. Records when a hunter arrived at the property and (optionally) when they left. Used for safety accountability and access validation.

```sql
CREATE TABLE check_ins (
    id                   UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id             UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    user_id              UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    stand_location_id    UUID        NULL,  -- References DB 13 (Geospatial) stand_locations.id
    checked_in_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    checked_out_at       TIMESTAMPTZ NULL,
    notes                TEXT        NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only log pattern — no updated_at, no deleted_at
CREATE INDEX idx_check_ins_lease_id       ON check_ins (lease_id);
CREATE INDEX idx_check_ins_user_id        ON check_ins (user_id);
CREATE INDEX idx_check_ins_checked_in_at  ON check_ins (checked_in_at);
```

**RLS Policy:**
```sql
ALTER TABLE check_ins ENABLE ROW LEVEL SECURITY;

CREATE POLICY check_ins_own_or_lessor ON check_ins
    FOR SELECT TO ah_app
    USING (
        user_id = current_setting('app.current_user_id')::UUID
        OR lease_id IN (
            SELECT id FROM leases
            WHERE lessor_user_id = current_setting('app.current_user_id')::UUID
        )
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `stand_location_id` is optional — only set if the hunter selects a stand location in the app before check-in.
- An open check-in (no `checked_out_at`) older than 24 hours triggers a wellness notification via `NotificationService`.
- SOS events from DB 7 reference `lease_id` and `user_id` to establish that the user was checked in.

---

### `lease_renewals`

Renewal offers sent to lessees before their lease expires. Tracks negotiation on renewal terms.

```sql
CREATE TABLE lease_renewals (
    id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id        UUID         NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    offered_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    offer_expires_at TIMESTAMPTZ NOT NULL,
    new_start       DATE         NOT NULL,
    new_end         DATE         NOT NULL,
    new_price       NUMERIC(10,2) NOT NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'accepted', 'rejected', 'expired')),
    responded_at    TIMESTAMPTZ  NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_lease_renewals_lease_id ON lease_renewals (lease_id);
CREATE INDEX idx_lease_renewals_status   ON lease_renewals (status);
```

---

### `signature_events`

Append-only e-signature audit trail. Records every significant event in the Dropbox Sign signing workflow. **Never deleted — not even soft-deleted.** This is the legally admissible record of the signing process.

```sql
CREATE TABLE signature_events (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id              UUID        NOT NULL REFERENCES leases (id),
    user_id               UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    provider              VARCHAR(50) NOT NULL DEFAULT 'dropbox_sign',
    provider_signature_id VARCHAR(255) NULL,
    event_type            VARCHAR(20) NOT NULL
                              CHECK (event_type IN ('sent', 'viewed', 'signed', 'declined', 'completed')),
    occurred_at           TIMESTAMPTZ NOT NULL,
    ip_address            INET        NULL,
    user_agent            TEXT        NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- NO deleted_at — this record is PERMANENT
);

-- Append-only — no updated_at, no deleted_at column
CREATE INDEX idx_signature_events_lease_id   ON signature_events (lease_id);
CREATE INDEX idx_signature_events_user_id    ON signature_events (user_id);
CREATE INDEX idx_signature_events_event_type ON signature_events (event_type);
CREATE INDEX idx_signature_events_occurred   ON signature_events (occurred_at);
```

**Notes:**
- Never call `delete()` or `forceDelete()` on `SignatureEvent` model instances. The model overrides both to throw a `\LogicException`.
- All writes to this table go through `EsignatureService::recordEvent()` — never write directly from controllers.
- `occurred_at` is the timestamp from the Dropbox Sign webhook payload — not `NOW()`.

---

### `esignature_requests`

Tracks a single Dropbox Sign signature request (one per lease document sent for signing). Updated by webhook callbacks.

```sql
CREATE TABLE esignature_requests (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id            UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    provider_request_id VARCHAR(255) NOT NULL,
    status              VARCHAR(15) NOT NULL DEFAULT 'pending'
                            CHECK (status IN ('pending', 'completed', 'declined', 'expired')),
    document_document_id UUID       NULL,  -- References DB 11 (Documents) documents.id — the signed PDF
    requested_at        TIMESTAMPTZ NOT NULL,
    completed_at        TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_esignature_requests_provider ON esignature_requests (provider_request_id);
CREATE        INDEX idx_esignature_requests_lease   ON esignature_requests (lease_id);
CREATE        INDEX idx_esignature_requests_status  ON esignature_requests (status);

CREATE TRIGGER trg_esignature_requests_updated_at
    BEFORE UPDATE ON esignature_requests
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `lease_notes`

Internal notes attached to a lease. Created by landowners and staff. Hunters do not see notes with `is_internal = true`.

```sql
CREATE TABLE lease_notes (
    id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id        UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
    author_user_id  UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    note            TEXT        NOT NULL,
    is_internal     BOOLEAN     NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ NULL
);

CREATE INDEX idx_lease_notes_lease_id ON lease_notes (lease_id);
```

**RLS Policy:**
```sql
ALTER TABLE lease_notes ENABLE ROW LEVEL SECURITY;

CREATE POLICY lease_notes_visibility ON lease_notes
    FOR SELECT TO ah_app
    USING (
        -- Staff and landowners see all notes
        current_setting('app.current_role') IN ('staff', 'super_admin', 'landowner')
        -- Hunters only see non-internal notes for their lease
        OR (
            is_internal = false
            AND lease_id IN (
                SELECT id FROM leases
                WHERE lessee_user_id = current_setting('app.current_user_id')::UUID
            )
        )
    );
```

---

## Eloquent Models

### `App\Models\Lease\Lease`

```php
<?php

namespace App\Models\Lease;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lease extends Model
{
    use SoftDeletes;

    protected $connection = 'lease';
    protected $table      = 'leases';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'application_id',
        'property_id',
        'listing_id',
        'lessee_user_id',
        'lessor_user_id',
        'status',
        'start_date',
        'end_date',
        'total_price',
        'deposit_paid',
        'auto_renew',
        'terminated_at',
        'termination_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'end_date'       => 'date',
            'total_price'    => 'decimal:2',
            'deposit_paid'   => 'decimal:2',
            'auto_renew'     => 'boolean',
            'terminated_at'  => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    public function application(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeaseApplication::class, 'application_id');
    }

    public function hunters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaseHunter::class, 'lease_id')->whereNull('deleted_at');
    }

    // Cross-DB: resolved via PropertyService
    public function getProperty(): ?\App\Models\Property\Property
    {
        return app(\App\Services\Property\PropertyService::class)->find($this->property_id);
    }

    // Cross-DB: resolved via UserService
    public function getLessee(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->find($this->lessee_user_id);
    }
}
```

### `App\Models\Lease\SignatureEvent`

```php
<?php

namespace App\Models\Lease;

use Illuminate\Database\Eloquent\Model;

class SignatureEvent extends Model
{
    protected $connection = 'lease';
    protected $table      = 'signature_events';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    // Signature events are legally permanent — never delete
    public function delete(): bool
    {
        throw new \LogicException('Signature events are permanent legal records and cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('Signature events are permanent legal records and cannot be deleted.');
    }
}
```

### `App\Models\Lease\LeaseApplication`

```php
use App\Models\Traits\HasEncryptedFields;
use Illuminate\Database\Eloquent\SoftDeletes;

protected $connection = 'lease';
protected $table      = 'lease_applications';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

use HasEncryptedFields, SoftDeletes;

// Encrypted at rest — read/write transparently via HasEncryptedFields
protected array $encryptedFields = ['message'];

protected function casts(): array
{
    return [
        'proposed_start' => 'date',
        'proposed_end'   => 'date',
        'reviewed_at'    => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];
}
```

### `App\Models\Lease\LeaseApplicationHunter`

```php
use App\Models\Traits\HasEncryptedFields;

protected $connection = 'lease';
protected $table      = 'lease_application_hunters';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

use HasEncryptedFields;

// 12 PII fields encrypted at rest via pgp_sym_encrypt (Key C)
protected array $encryptedFields = [
    'email', 'home_phone', 'cell_phone',
    'address_line1', 'address_line2', 'city',
    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    'medical_conditions', 'dl_number', 'hunting_license_number',
];

protected function casts(): array
{
    return [
        'date_of_birth'                => 'date',
        'dl_expiry'                    => 'date',
        'hunting_license_expiry'       => 'date',
        'is_minor'                     => 'boolean',
        'guardian_consent_obtained'    => 'boolean',
        'background_check_consented'   => 'boolean',
        'created_at'                   => 'datetime',
        'updated_at'                   => 'datetime',
    ];
}
```

### `App\Models\Lease\Club`

```php
protected $connection = 'lease';
protected $table      = 'clubs';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'membership_fee' => 'decimal:2',
        'is_public'      => 'boolean',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];
}
```

---

## Service Notes

- **`LeaseService`** — core service for lease creation, status transitions, hunter management, and cross-DB assembly. At `App\Services\Lease\LeaseService`. Caches assembled lease detail DTOs in Valkey Cluster 2 with key `lease_detail:{id}`.
- **`ApplicationService`** — handles the application submission, review, approval/rejection workflow, and notification triggers. At `App\Services\Lease\ApplicationService`.
- **`EsignatureService`** — sends Dropbox Sign requests, processes webhooks, records `signature_events`, and transitions lease status to `active` when all signatures are collected. At `App\Services\Lease\EsignatureService`.
- **`ClubService`** — manages club creation, membership, and club lease applications. At `App\Services\Lease\ClubService`.
- Invalidate `lease_detail:{id}` in Valkey after any update to `leases`, `lease_hunters`, `check_ins`, or `esignature_requests` for that lease.
- **Queue jobs:** `SendLeaseSignatureRequest` (priority), `ProcessEsignatureWebhook` (priority), `ActivateLeaseAfterSignatures` (priority), `CreateLeaseRenewalOfferJob` (default), `ExpireLeaseJob` (default).
