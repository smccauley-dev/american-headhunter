# DB 10 — Incidents & Safety

**Server:** High-security dedicated PostgreSQL
**Encryption Key:** Key J — rotated annually
**Laravel Connection:** `incidents`
**Database:** `ah_incidents`
**DB User:** `ah_app`
**Access:** Application incident service, admin safety team, insurance partners (scoped read — policy-referenced records only), legal counsel (read-only with legal hold context)

---

## Purpose

Safety events, property damage, disputes, and emergency records for the platform. SOS-linked incident records are permanent and may never be soft-deleted or hard-deleted. Insurance and legal partners have narrowly scoped read-only access — they can only pull records directly tied to their policy numbers or case references. All sensitive descriptions are treated as potentially attorney-client privileged once an escalated status is reached.

---

## Extensions Required

```sql
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
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

### incident_reports
Safety incidents occurring on or related to a property. Covers hunting accidents, trespassing, property damage, wildlife encounters, and medical emergencies.

```sql
CREATE TABLE incident_reports (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id             UUID NOT NULL,           -- References DB 2 (Property) properties.id
    lease_id                UUID,                    -- References DB 3 (Lease) leases.id — NULL if no active lease
    reporter_user_id        UUID NOT NULL,           -- References DB 1 (Identity) users.id
    incident_type           VARCHAR(30) NOT NULL,
    severity                VARCHAR(20) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'open',
    occurred_at             TIMESTAMPTZ NOT NULL,
    location_description    TEXT,
    description             TEXT NOT NULL,
    injuries_reported       BOOLEAN NOT NULL DEFAULT false,
    authorities_notified    BOOLEAN NOT NULL DEFAULT false,
    authority_report_number VARCHAR(100),
    resolved_at             TIMESTAMPTZ,
    resolution_notes        TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ,

    CONSTRAINT chk_incident_reports_type
        CHECK (incident_type IN (
            'hunting_accident', 'trespassing', 'property_damage',
            'wildlife_encounter', 'medical', 'other'
        )),
    CONSTRAINT chk_incident_reports_severity
        CHECK (severity IN ('minor', 'moderate', 'serious', 'critical')),
    CONSTRAINT chk_incident_reports_status
        CHECK (status IN ('open', 'investigating', 'resolved', 'closed'))
);

CREATE INDEX idx_incident_reports_property_id ON incident_reports (property_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_incident_reports_lease_id ON incident_reports (lease_id)
    WHERE lease_id IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_incident_reports_reporter ON incident_reports (reporter_user_id);
CREATE INDEX idx_incident_reports_status ON incident_reports (status, severity)
    WHERE deleted_at IS NULL AND status IN ('open', 'investigating');
CREATE INDEX idx_incident_reports_occurred_at ON incident_reports (occurred_at DESC);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON incident_reports
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### sos_incident_records
Links a formal incident report to an SOS event from DB 7. These records are **PERMANENT** — SOS-originated records are life-safety records and may never be soft-deleted or hard-deleted under any circumstances.

```sql
CREATE TABLE sos_incident_records (
    id                          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    sos_event_log_id            UUID NOT NULL,       -- References DB 7 (Communications) sos_event_log.id
    incident_id                 UUID REFERENCES incident_reports (id),  -- NULL if no formal incident was opened
    responding_users            JSONB NOT NULL DEFAULT '[]',            -- Array of user UUIDs who acknowledged/responded
    emergency_services_called   BOOLEAN NOT NULL DEFAULT false,
    call_911_time               TIMESTAMPTZ,
    response_time_minutes       SMALLINT,
    outcome                     VARCHAR(50),         -- e.g. 'false_alarm', 'resolved_on_site', 'hospitalized', 'fatality'
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- NO deleted_at — EVER. Life-safety record.
    -- NO updated_at — corrections are appended via incident_reports
);

CREATE INDEX idx_sos_incident_records_sos_event ON sos_incident_records (sos_event_log_id);
CREATE INDEX idx_sos_incident_records_incident_id ON sos_incident_records (incident_id)
    WHERE incident_id IS NOT NULL;
CREATE INDEX idx_sos_incident_records_created_at ON sos_incident_records (created_at DESC);
```

**ENFORCEMENT NOTE:** No soft-delete, hard-delete, or update is ever permitted on `sos_incident_records`. The `SosService` enforces this. If the linked `incident_id` needs updating, update the `incident_reports` row — the link row is permanent as-created.

---

### lease_disputes
Formal disputes between lessees and landowners. The first concrete use is the **forfeiture-contest loop**: when a landowner forfeits a hunter's security deposit, that forfeiture is only a *claim* (money held, Trust Score provisional); the hunter contests it here with photo evidence and an admin adjudicates. Supports mediation, arbitration, and admin escalation workflows.

**Built ahead of the rest of DB 10** (`incident_reports`, `sos_incident_records`, `content_moderation` are still deferred), so its migration defensively installs the pgcrypto/uuid extensions and the shared `trigger_set_updated_at` function. Adds two columns beyond the original spec: `security_deposit_id` (the contested forfeiture, DB 4) and `evidence_document_ids` (DB 11 photo proof) — both bare UUID refs assembled in the service layer, never SQL foreign keys.

```sql
CREATE TABLE lease_disputes (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id                UUID NOT NULL,           -- References DB 3 (Lease) leases.id
    security_deposit_id     UUID,                    -- References DB 4 (Billing) security_deposits.id — the contested forfeiture
    initiator_user_id       UUID NOT NULL,           -- References DB 1 (Identity) users.id (the contesting hunter)
    respondent_user_id      UUID NOT NULL,           -- References DB 1 (Identity) users.id (the landowner)
    dispute_type            VARCHAR(20) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'open',
    description             TEXT NOT NULL,
    amount_disputed_cents   BIGINT,                  -- NULL if not a financial dispute
    evidence_document_ids   JSONB NOT NULL DEFAULT '[]',    -- Array of DB 11 documents.id UUIDs (photo proof)
    resolution              TEXT,
    resolved_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ,

    CONSTRAINT chk_lease_disputes_type
        CHECK (dispute_type IN ('payment', 'access', 'damage', 'breach', 'other')),
    CONSTRAINT chk_lease_disputes_status
        CHECK (status IN ('open', 'mediation', 'arbitration', 'resolved', 'escalated')),
    CONSTRAINT chk_lease_disputes_parties
        CHECK (initiator_user_id <> respondent_user_id)
);

CREATE INDEX idx_lease_disputes_lease_id ON lease_disputes (lease_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_lease_disputes_deposit ON lease_disputes (security_deposit_id)
    WHERE security_deposit_id IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_lease_disputes_initiator ON lease_disputes (initiator_user_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_lease_disputes_respondent ON lease_disputes (respondent_user_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_lease_disputes_status ON lease_disputes (status)
    WHERE deleted_at IS NULL AND status NOT IN ('resolved');

CREATE TRIGGER set_updated_at BEFORE UPDATE ON lease_disputes
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

-- System-authored, runtime-read-only (SEC-045): RLS on, a single FOR SELECT policy
-- (parties + staff), NO write policy → INSERT/UPDATE/DELETE default-deny under
-- ah_runtime. Members file via the db.system route (ah_system); admin adjudicates.
ALTER TABLE lease_disputes ENABLE ROW LEVEL SECURITY;

CREATE POLICY lease_disputes_parties_and_staff ON lease_disputes
    FOR SELECT TO ah_runtime
    USING (
        initiator_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
        OR respondent_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
        OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
    );
```

---

### damage_claims
Claims for property or equipment damage filed by a landowner. Tracks submission, review, approval, payment, and (when applicable) insurance coverage. An approved claim can optionally be settled from the lease's held deposit, which records a forfeiture-claim that then follows the same contest/adjudication loop as `lease_disputes`.

Adds `security_deposit_id` (the deposit a claim was settled from) and an **insurance block** (`insurance_covered_party`, `insurer_name`, `policy_number`, `coi_document_id`, `coverage_status`) so a covered loss can route through insurance instead of a Trust Score penalty. A `'covered'` status + `review_notes` column carry the insurance-settlement outcome.

```sql
CREATE TABLE damage_claims (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id                UUID NOT NULL,           -- References DB 3 (Lease) leases.id
    security_deposit_id     UUID,                    -- References DB 4 (Billing) security_deposits.id — set when settled from the deposit
    claimant_user_id        UUID NOT NULL,           -- References DB 1 (Identity) users.id — the landowner
    claim_type              VARCHAR(30) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'submitted',
    description             TEXT NOT NULL,
    amount_claimed_cents    BIGINT NOT NULL,
    amount_approved_cents   BIGINT,                  -- NULL until reviewed
    evidence_document_ids   JSONB NOT NULL DEFAULT '[]',    -- Array of DB 11 documents.id UUIDs
    insurance_covered_party VARCHAR(12)  NULL CHECK (insurance_covered_party IN ('landowner','hunter','none')),
    insurer_name            VARCHAR(120) NULL,
    policy_number           VARCHAR(80)  NULL,
    coi_document_id         UUID         NULL,       -- References DB 11 (Documents) documents.id (insurance_certificate)
    coverage_status         VARCHAR(12)  NULL CHECK (coverage_status IN ('none','claimed','covered','denied')),
    reviewed_by_user_id     UUID,                    -- References DB 1 (Identity) users.id — admin reviewer
    review_notes            TEXT,
    resolved_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ,

    CONSTRAINT chk_damage_claims_type
        CHECK (claim_type IN ('property_damage', 'equipment_damage', 'other')),
    CONSTRAINT chk_damage_claims_status
        CHECK (status IN ('submitted', 'under_review', 'approved', 'denied', 'paid', 'covered')),
    CONSTRAINT chk_damage_claims_amount
        CHECK (amount_claimed_cents > 0)
);

CREATE INDEX idx_damage_claims_lease_id ON damage_claims (lease_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_damage_claims_claimant ON damage_claims (claimant_user_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_damage_claims_status ON damage_claims (status)
    WHERE deleted_at IS NULL AND status NOT IN ('paid', 'denied', 'covered');
CREATE INDEX idx_damage_claims_reviewer ON damage_claims (reviewed_by_user_id)
    WHERE reviewed_by_user_id IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON damage_claims
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

-- System-authored, runtime-read-only (SEC-045): the claimant reads their own claim,
-- staff/super_admin read all; no write policy → ah_runtime writes default-deny.
-- Landowners file via the db.system member route; admin reviews via Filament.
ALTER TABLE damage_claims ENABLE ROW LEVEL SECURITY;

CREATE POLICY damage_claims_claimant_and_staff ON damage_claims
    FOR SELECT TO ah_runtime
    USING (
        claimant_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
        OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
    );
```

---

### content_moderation
Flagged content (listings, profiles, messages, photos, reviews) reported by users or caught by automated rules. Drives the moderation queue in the admin backend.

```sql
CREATE TABLE content_moderation (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    content_type            VARCHAR(20) NOT NULL,
    content_id              UUID NOT NULL,           -- The ID of the flagged entity in its home database
    content_db              VARCHAR(50) NOT NULL,    -- Which database the content lives in
    reported_by_user_id     UUID NOT NULL,           -- References DB 1 (Identity) users.id
    reason                  VARCHAR(100) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'pending',
    action_taken            VARCHAR(100),            -- e.g. 'content_removed', 'user_warned', 'user_suspended', 'no_action'
    reviewed_by_user_id     UUID,                    -- References DB 1 (Identity) users.id — admin reviewer
    reviewed_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ,

    CONSTRAINT chk_content_moderation_type
        CHECK (content_type IN ('listing', 'profile', 'message', 'photo', 'review')),
    CONSTRAINT chk_content_moderation_status
        CHECK (status IN ('pending', 'reviewed', 'actioned', 'dismissed'))
);

CREATE INDEX idx_content_moderation_content ON content_moderation (content_type, content_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_content_moderation_status ON content_moderation (status, created_at)
    WHERE deleted_at IS NULL AND status = 'pending';
CREATE INDEX idx_content_moderation_reporter ON content_moderation (reported_by_user_id);
CREATE INDEX idx_content_moderation_reviewer ON content_moderation (reviewed_by_user_id)
    WHERE reviewed_by_user_id IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON content_moderation
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

## Eloquent Models

```php
namespace App\Models\Incidents;

class IncidentReport extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'incidents';
    protected $table      = 'incident_reports';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'occurred_at'          => 'datetime',
            'resolved_at'          => 'datetime',
            'injuries_reported'    => 'boolean',
            'authorities_notified' => 'boolean',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Incidents;

class SosIncidentRecord extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'incidents';
    protected $table      = 'sos_incident_records';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    // No soft deletes — SOS records are permanent.

    protected function casts(): array
    {
        return [
            'responding_users'        => 'array',
            'emergency_services_called' => 'boolean',
            'call_911_time'           => 'datetime',
            'created_at'              => 'datetime',
        ];
    }

    /**
     * SOS incident records are permanent and may NEVER be deleted.
     */
    public function delete(): bool
    {
        throw new \LogicException(
            'SosIncidentRecord records are permanent life-safety records and cannot be deleted.'
        );
    }

    public function forceDelete(): bool
    {
        throw new \LogicException(
            'SosIncidentRecord records are permanent life-safety records and cannot be deleted.'
        );
    }
}
```

```php
namespace App\Models\Incidents;

class LeaseDispute extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'lease_disputes';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Incidents;

class DamageClaim extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'damage_claims';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'evidence_document_ids' => 'array',
            'resolved_at'           => 'datetime',
            'created_at'            => 'datetime',
            'updated_at'            => 'datetime',
            'deleted_at'            => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Incidents;

class ContentModeration extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'content_moderation';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'deleted_at'  => 'datetime',
        ];
    }
}
```

---

## Service Layer

DB 10 has **no single `IncidentService`** — that class in the original spec was illustrative. Each table is owned by a focused service, and all cross-DB assembly (lease → deposit → users → documents) happens in the service layer, never via Eloquent relationships. The two live entry points are:

### `App\Services\Incidents\DisputeService` — forfeiture-contest loop (`lease_disputes`)

- `fileForfeitureContest(Lease, User $hunter, string $description, array $evidenceDocIds): LeaseDispute` — derives the respondent (landowner) and contested `security_deposit_id` from the lease, guards the deposit is a *pending* hunter-fault forfeiture-claim not already disputed, attaches the evidence photos, opens the dispute. Called by the member db.system route.
- `resolve(string $disputeId, string $outcome, ?string $actorUserId, ?string $note, array $opts = []): LeaseDispute` — adjudicates. `outcome ∈ {uphold, overturn, opt_out}`, each finalizing the contested deposit through `SecurityDepositService` (the **only** place money + Trust Score move):
  - **uphold** → `confirmForfeitFault` (hunter −10, money to landowner).
  - **overturn** → `waiveForfeitFault` (refund hunter) + landowner −10 (`dispute_resolved_against_user`); optional hunter +5 (`dispute_resolved_for_user`) via `$opts['credit_initiator']`.
  - **opt_out** → `optOutForfeitDecision($opts['disposition'])` (`keep`|`refund`, no Trust Score). Insurance-settled.
- `openDisputeFor(depositId)` / `latestForDeposit(depositId)` — read helpers for the member portal and guards.

### `App\Services\Incidents\DamageClaimService` — landowner damage claims (`damage_claims`)

- `file(Lease, User $claimant, string $type, int $amountCents, string $description, array $evidenceDocIds, array $insurance): DamageClaim` — files a `'submitted'` claim. Called by the member db.system route (lessor only).
- `review(string $claimId, string $decision, ?int $amountApprovedCents, ?string $actorUserId, ?string $note): DamageClaim` — `decision ∈ {approved, denied, paid, covered}`; `approved` requires an amount ≤ claimed.
- `forfeitDepositForApproved(string $claimId, ?string $actorUserId): DamageClaim` — settles an approved claim from the lease's held deposit by recording a forfeiture-claim (fault = lessee), capped at the deposit's remaining balance; links the deposit and marks the claim paid.
- `forLease(leaseId)` — newest-first claims for member-portal display.

Both services write every mutation through `AuditService` (which never throws). Writes are system-authored: the member routes run under `db.system` (ah_system, BYPASSRLS) and the Filament `LeaseDisputeResource` / `DamageClaimResource` adjudication pages run inside the admin panel (also ah_system).

---

## Common Pitfalls

- **Never soft-delete or hard-delete `sos_incident_records`.** The `SosIncidentRecord` model throws on any delete attempt. If a record was created in error, document the error in the linked `incident_reports` resolution notes.
- **Never cross-database join in SQL.** Fetching property or user details for an incident always goes through the service layer.
- **`content_id` is not a foreign key.** It references content in other databases — it is a bare UUID column. Always resolve through the appropriate service.
- **Soft-deleted incidents still appear in admin safety dashboards.** Always check the `IncidentService` query scope rather than writing raw queries that might miss soft-delete filtering.
