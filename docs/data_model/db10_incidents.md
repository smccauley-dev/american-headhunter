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
Safety incidents occurring on or related to a property. Covers hunting accidents, trespassing, property damage, wildlife encounters, fire, and medical emergencies. **A single real-world event can be several of these at once** (e.g. a fire AND a medical injury).

**Built.** Adds these columns beyond the original spec — all bare UUID refs assembled in the service layer, never SQL foreign keys:
- `incident_items JSONB NOT NULL DEFAULT '[]'` — the **line items**: a list of `{ type, severity, occurred_at }`, one per kind of incident in the same event. The scalar `incident_type` / `severity` / `occurred_at` columns are kept as a service-maintained **lead** derived from the items (lead type = first item; `severity` = the *worst* across items; `occurred_at` = the *earliest*), so the existing CHECKs, badges, filters, sorts, and the `(status, severity)` / `(occurred_at DESC)` indexes keep working. Item-level type/severity are validated at the request/service layer (they live inside JSONB), while the scalar lead columns stay CHECK-guarded. Member/admin UIs combine the item types into one title, e.g. "Fire · Medical".
- `evidence_document_ids JSONB NOT NULL DEFAULT '[]'` — array of DB 11 `documents.id` UUIDs (photo proof). **Append-only: once a photo is uploaded it can never be removed.** Edits may add photos but no code path (member or admin) deletes one.
- `listing_id UUID` — the property's DB 2 `property_listings.id`, resolved from `property_id` at file time and denormalised so the case-number sequence is stable even if the property is re-listed.
- `incident_number VARCHAR(40)` (unique, partial index on `incident_number IS NOT NULL`) — the human-facing case number `IR-<first 8 chars of the listing id, uppercased>-<NN>`, where `NN` is a per-listing sequence (`01`, `02`, …). Falls back to the `property_id` prefix when a property has no listing.

The table is **system-authored, runtime-read-only** (SEC-045): RLS enabled with a single `FOR SELECT TO ah_runtime` policy (`reporter_user_id = current user OR role in staff/super_admin`) and **no write policy**, so the inherited DML grant is inert for writes — only the trusted `ah_system` path (the `db.system` member routes that file/edit a report, and the Filament admin panel that triages it) may author or mutate rows. Uses soft deletes. Entry point: **`App\Services\Incidents\IncidentService`**:
- `file(Lease, User $reporter, array $data, array $evidenceDocIds)` — member intake; guards the reporter is a lease party, derives `property_id` from the lease, normalises `$data['items']` (the line items) and derives the scalar lead, allocates the `incident_number`.
- `updateDetails($id, $data, $actorUserId, $addEvidenceDocIds)` — corrects the line items (replaced as a set via `$data['items']`, re-deriving the scalar lead) and the descriptive fields (`EDITABLE_FIELDS`: location, description, injury/authority flags), and **appends** (never removes) photo evidence. Records a field-level before/after diff (`incident_report.updated`) and a separate `incident_report.evidence_added` event, both attributed to the actor. Used by both the reporter (member edit, only while `open`/`investigating`) and admins.
- `updateStatus($id, $status, $actorUserId, $extra)` — safety-team triage: `open → investigating → resolved → closed`, capturing authority + resolution detail.

Every write is audited via `AuditService`; the admin View page renders the full who/what/when change history from the DB 9 audit log. Member edit/photo routes: `POST /member/leases/{lease}/incidents/{incident}` (reporter-only, `db.system`) and `GET …/photos/{documentId}` (serves the reporter their evidence, RLS-scoped).

```sql
CREATE TABLE incident_reports (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    property_id             UUID NOT NULL,           -- References DB 2 (Property) properties.id
    listing_id              UUID,                    -- References DB 2 (Property) property_listings.id (case-number scope)
    incident_number         VARCHAR(40),             -- IR-<listing id8>-<NN>, unique (partial index)
    lease_id                UUID,                    -- References DB 3 (Lease) leases.id — NULL if no active lease
    reporter_user_id        UUID NOT NULL,           -- References DB 1 (Identity) users.id
    incident_type           VARCHAR(30) NOT NULL,           -- lead type (first line item)
    severity                VARCHAR(20) NOT NULL,           -- lead severity (worst across line items)
    incident_items          JSONB NOT NULL DEFAULT '[]',    -- line items: [{type, severity, occurred_at}, …]
    parties_involved        JSONB NOT NULL DEFAULT '[]',    -- involved people: [{full_name, is_minor}, …]; is_minor = "under 18" flag (no DOB stored)
    status                  VARCHAR(20) NOT NULL DEFAULT 'open',
    occurred_at             TIMESTAMPTZ NOT NULL,           -- lead occurred_at (earliest line item)
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
            'wildlife_encounter', 'medical', 'fire', 'other'
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

CREATE UNIQUE INDEX uq_incident_reports_number ON incident_reports (incident_number)
    WHERE incident_number IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON incident_reports
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### incident_admin_notes
The safety team's **admin-only investigation log** for an incident — one timestamped line-item per note, newest first in the admin UI, **never shown to the reporter**. Append-only: there is no `updated_at`/`deleted_at` and the UI exposes no edit/delete — a note, once taken, stands as a record.

Admin-only **by construction, not just convention.** `incident_reports` lets the reporter read their own row, so an admin-only column on that table would leak. This separate table's RLS SELECT policy is gated to **staff/super_admin only** — the reporter shares the parent incident but can never read its notes, even at the database level. There is no write policy, so the inherited `ah_runtime` DML grant is inert for writes (SEC-045): notes are authored only by `ah_system` (the Filament admin panel). `author_user_id` is a bare cross-DB (DB 1) UUID resolved in the service/resource layer; `incident_report_id` is an intra-DB FK (same database, so a real foreign key is allowed) with `ON DELETE CASCADE`.

```sql
CREATE TABLE incident_admin_notes (
    id                 UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    incident_report_id UUID NOT NULL REFERENCES incident_reports (id) ON DELETE CASCADE,
    author_user_id     UUID NOT NULL,           -- References DB 1 (Identity) users.id
    note               TEXT NOT NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_incident_admin_notes_report
    ON incident_admin_notes (incident_report_id, created_at DESC);

ALTER TABLE incident_admin_notes ENABLE ROW LEVEL SECURITY;

-- Staff/super_admin only — the reporter must never read investigation notes.
-- No INSERT/UPDATE/DELETE policy: writes are system-authored (ah_system).
CREATE POLICY incident_admin_notes_staff_only ON incident_admin_notes
    FOR SELECT TO ah_runtime
    USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));
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

The original spec's single, catch-all `IncidentService` was illustrative — DB 10 is instead split so each table is owned by a focused service, and all cross-DB assembly (lease → deposit → users → documents) happens in the service layer, never via Eloquent relationships. The live entry points are:

### `App\Services\Incidents\IncidentService` — safety-incident intake & triage (`incident_reports`)

- `file(Lease, User $reporter, array $data, array $evidenceDocIds = []): IncidentReport` — member intake; guards the reporter is a party to the lease (lessee or lessor), derives `property_id` from the lease, normalises the line items (`$data['items']` = list of `{type, severity, occurred_at}`) and derives the scalar lead (lead type, worst severity, earliest time), allocates the `incident_number` (`IR-<listing id8>-<NN>`, per-listing sequence), attaches photo evidence, opens the report. Called by the member db.system route.
- `updateStatus(string $incidentId, string $status, ?string $actorUserId, array $extra = []): IncidentReport` — safety-team triage through `open → investigating → resolved → closed` (transitions validated; resolving/closing stamps `resolved_at`), optionally capturing `authorities_notified` / `authority_report_number` / `resolution_notes`. Called by the Filament `IncidentReportResource` view-page actions. Audits the `old → new` status.
- `updateDetails(string $incidentId, array $data, ?string $actorUserId, array $addEvidenceDocIds = []): IncidentReport` — replaces the line items as a set (`$data['items']`, re-deriving the scalar lead), replaces the involved parties as a set (`$data['parties']` = list of `{full_name, is_minor}`; nameless rows dropped), and corrects the descriptive `EDITABLE_FIELDS` (location, description, injury/authority flags). Only changed keys are written; the edit is recorded as a field-level **before/after diff** in the audit log (`incident_report.updated`), attributed to the actor. Party changes are audited separately as `incident_report.parties_updated` recording **counts only** (`party_count` / `minor_count`) — minors' names are never written to the audit log. `$addEvidenceDocIds` **appends** photos (never removes existing ones — photos are permanent once uploaded) and is audited separately as `incident_report.evidence_added`. Called by both the view-page **Edit Details** admin action and the reporter's member edit route (`POST /member/leases/{lease}/incidents/{incident}`, reporter-only, allowed only while `open`/`investigating`).
- `addAdminNote(string $incidentId, string $note, string $actorUserId): IncidentAdminNote` — appends one admin-only investigation note (`incident_admin_notes`), trimmed and non-empty, attributed to the actor and audited (`incident_report.note_added`, keyed by the note id). Staff-authored only — the reporter never sees these. Called by the Filament view-page **Add Note** action.
- `adminNotes(string $incidentId): Collection<IncidentAdminNote>` — an incident's investigation notes, newest first, for the admin **Investigation Notes** section.
- `forLease(leaseId)` / `forProperty(propertyId)` — newest-occurrence-first reads for the member portal and admin dashboards.

Every incident write (`incident_report.filed`, `.status_changed`, `.updated`, `.parties_updated`, `.evidence_added`, `.note_added`) lands in the immutable audit log (DB 9). Report-field changes are keyed by `record_id = incident.id`; note additions by the note id. The Filament view page renders report-field history as a **Change History** timeline — who changed what, before → after, with timestamps — via `IncidentReportResource::changeLog()`, and the investigation notes (admin-only) as a separate **Investigation Notes** section via `adminNotesList()` (author ids resolved to names cross-DB).

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
