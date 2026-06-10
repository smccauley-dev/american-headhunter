# DB 9 — Audit & Compliance

**Server:** Append-only dedicated PostgreSQL — database-level immutability enforced via PostgreSQL RULEs
**Encryption Key:** Key I — rotated annually
**Laravel Connection:** `audit`
**Database:** `ah_audit`
**DB User:** `ah_app` (INSERT only — no UPDATE or DELETE privileges granted at the PostgreSQL role level)
**Access:** AuditService (INSERT only), compliance auditors (read-only scoped connection), SOC 2 auditors (read-only), legal counsel (read-only with legal hold context)

---

## CRITICAL RULES

1. **NEVER call `update()` or `delete()` on any audit model.** All audit models extend `ImmutableModel`, which throws a `LogicException` on any write attempt other than INSERT.
2. **PostgreSQL RULEs block UPDATE and DELETE at the database level** regardless of application behavior. Any attempt to mutate an audit row is silently discarded at the DB engine level.
3. **Always write audit events through `AuditService`.** Never write to `audit_log` directly from controllers, models, or other services.
4. **`AuditService` must never throw.** All writes are wrapped in `try/catch` internally. Audit failures are logged to the application log and the calling transaction continues unaffected.
5. **10-year minimum retention.** No automated purges. Records are permanent by design.
6. **No `updated_at` or `deleted_at` columns exist on any table in this database.** Immutable rows have only `created_at`.

---

## Purpose

An immutable, court-admissible record of every material action taken on the platform. Covers: authentication events, data mutations, admin impersonation, e-signature milestones, OFAC screening, CCPA/privacy requests, consent captures, legal holds, dual-approval workflows, and breach incidents. Satisfies SOC 2 Type II, CCPA audit requirements, and hunting regulation compliance obligations.

---

## Extensions Required

```sql
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

---

## Immutability Enforcement

PostgreSQL RULEs are applied to every table in this database at migration time. They operate at the storage layer — no amount of application-level misconfiguration can bypass them.

```sql
-- Pattern applied to every table in DB 9
-- Replace 'audit_log' with the actual table name for each table.

CREATE RULE no_update_audit_log AS
    ON UPDATE TO audit_log DO INSTEAD NOTHING;

CREATE RULE no_delete_audit_log AS
    ON DELETE TO audit_log DO INSTEAD NOTHING;
```

The `ah_app` database user is additionally granted only `INSERT` and `SELECT` on all tables in this database — no `UPDATE` or `DELETE` privileges exist at the role level. Defense-in-depth.

---

## ImmutableModel Base Class

All audit Eloquent models extend `ImmutableModel`. This is the application-level enforcement layer — the RULE at the database level is the ultimate enforcement.

```php
namespace App\Models\Audit;

abstract class ImmutableModel extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'audit';
    public $timestamps    = false;   // PostgreSQL triggers manage created_at only
    public $incrementing  = false;
    protected $keyType    = 'string';

    /**
     * Immutable models allow INSERT (create) only.
     * Attempting to call save() on an existing record throws.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException(
                static::class . ' is immutable. Audit records cannot be updated. Write a new record instead.'
            );
        }
        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(static::class . ' is immutable. Audit records cannot be updated.');
    }

    public function delete(): bool
    {
        throw new \LogicException(static::class . ' is immutable. Audit records cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException(static::class . ' is immutable. Audit records cannot be deleted.');
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        throw new \LogicException(static::class . ' is immutable.');
    }
}
```

---

## AuditService Pattern

```php
namespace App\Services\Audit;

class AuditService
{
    /**
     * Write an audit event. NEVER throws — failures are swallowed and logged.
     */
    public function log(
        string  $eventType,
        ?string $subjectType = null,
        ?string $subjectId   = null,
        ?string $subjectDb   = null,
        ?array  $beforeState = null,
        ?array  $afterState  = null,
        array   $metadata    = []
    ): void {
        try {
            AuditLog::create([
                'event_type'       => $eventType,
                'actor_user_id'    => auth()->id(),
                'actor_ip'         => request()->ip(),
                'actor_user_agent' => request()->userAgent(),
                'subject_type'     => $subjectType,
                'subject_id'       => $subjectId,
                'subject_db'       => $subjectDb,
                'before_state'     => $beforeState ? json_encode($this->sanitize($beforeState)) : null,
                'after_state'      => $afterState  ? json_encode($this->sanitize($afterState))  : null,
                'metadata'         => json_encode($metadata),
            ]);
        } catch (\Throwable $e) {
            // Audit failures must NEVER bubble up and break user-facing transactions.
            \Log::error('AuditService write failed', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip encrypted field values before storing — never log raw encrypted data.
     */
    private function sanitize(array $state): array
    {
        $encryptedFields = ['phone', 'address_line1', 'address_line2', 'gate_code',
                            'emergency_contact_phone', 'ssn_last_four'];
        foreach ($encryptedFields as $field) {
            if (isset($state[$field])) {
                $state[$field] = '[REDACTED]';
            }
        }
        return $state;
    }
}
```

Usage:

```php
// In a service method — after a successful operation
app(AuditService::class)->log(
    eventType:   'lease.activated',
    subjectType: 'lease',
    subjectId:   $lease->id,
    subjectDb:   'lease',
    beforeState: ['status' => 'pending_signatures'],
    afterState:  ['status' => 'active'],
    metadata:    ['triggered_by' => 'esignature_webhook']
);

// Do NOT catch exceptions from AuditService at the call site.
// AuditService catches its own exceptions internally.
```

---

## Tables

### audit_log
The central immutable event log. Every material action on the platform produces a row here.

Common `event_type` values: `user.created`, `user.login`, `user.login_failed`, `user.password_changed`, `user.mfa_enabled`, `user.suspended`, `lease.created`, `lease.activated`, `lease.terminated`, `payment.processed`, `payment.refunded`, `sos.triggered`, `admin.impersonation_started`, `data.exported`, `data.deleted`, `consent.accepted`, `ofac.hit`, `feature_flag.changed`

```sql
CREATE TABLE audit_log (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    event_type          VARCHAR(100) NOT NULL,
    actor_user_id       UUID,                       -- References DB 1 (Identity) users.id — NULL for system events
    actor_ip            INET,
    actor_user_agent    TEXT,
    subject_type        VARCHAR(100),               -- The model/entity type being acted upon (e.g. 'lease', 'user')
    subject_id          UUID,                       -- The entity's ID in its home database
    subject_db          VARCHAR(50),                -- Which database the subject lives in (e.g. 'lease', 'billing')
    before_state        JSONB,                      -- Sanitized snapshot — no encrypted values, no raw PII
    after_state         JSONB,                      -- Sanitized snapshot
    metadata            JSONB NOT NULL DEFAULT '{}',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- NO updated_at — immutable
    -- NO deleted_at — permanent
);

CREATE INDEX idx_audit_log_event_type ON audit_log (event_type, created_at);
CREATE INDEX idx_audit_log_actor_user_id ON audit_log (actor_user_id, created_at)
    WHERE actor_user_id IS NOT NULL;
CREATE INDEX idx_audit_log_subject ON audit_log (subject_type, subject_id, created_at)
    WHERE subject_id IS NOT NULL;
CREATE INDEX idx_audit_log_created_at ON audit_log (created_at);

CREATE RULE no_update_audit_log AS ON UPDATE TO audit_log DO INSTEAD NOTHING;
CREATE RULE no_delete_audit_log AS ON DELETE TO audit_log DO INSTEAD NOTHING;
```

---

### compliance_flags
Items flagged for compliance review. Written by automated rules or manually by compliance staff. Corrections are new rows — the original row is never modified.

```sql
CREATE TABLE compliance_flags (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    audit_log_id            UUID NOT NULL REFERENCES audit_log (id),
    flag_type               VARCHAR(50) NOT NULL,   -- e.g. 'ofac_hit', 'large_cash_transaction', 'sar_candidate'
    severity                VARCHAR(20) NOT NULL,
    description             TEXT NOT NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'open',
    assigned_to_user_id     UUID,                   -- References DB 1 (Identity) users.id
    resolved_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — corrections are new rows
    -- No deleted_at — permanent

    CONSTRAINT chk_compliance_flags_severity
        CHECK (severity IN ('low', 'medium', 'high', 'critical')),
    CONSTRAINT chk_compliance_flags_status
        CHECK (status IN ('open', 'investigating', 'resolved', 'false_positive'))
);

CREATE INDEX idx_compliance_flags_audit_log_id ON compliance_flags (audit_log_id);
CREATE INDEX idx_compliance_flags_status ON compliance_flags (status, severity)
    WHERE status IN ('open', 'investigating');
CREATE INDEX idx_compliance_flags_assigned ON compliance_flags (assigned_to_user_id)
    WHERE assigned_to_user_id IS NOT NULL AND resolved_at IS NULL;

CREATE RULE no_update_compliance_flags AS ON UPDATE TO compliance_flags DO INSTEAD NOTHING;
CREATE RULE no_delete_compliance_flags AS ON DELETE TO compliance_flags DO INSTEAD NOTHING;
```

---

### data_retention_log
Records when data was purged per retention policy. The purge log itself is permanent — you always know what was deleted and when.

```sql
CREATE TABLE data_retention_log (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    table_name          VARCHAR(100) NOT NULL,
    record_id           UUID NOT NULL,
    database_name       VARCHAR(50) NOT NULL,
    purged_at           TIMESTAMPTZ NOT NULL,
    retention_policy    VARCHAR(50) NOT NULL,       -- e.g. '7_year_financial', '10_year_audit', 'ccpa_deletion'
    purged_by           VARCHAR(50) NOT NULL,       -- 'system' or admin user ID string
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No updated_at — immutable
    -- No deleted_at — permanent
);

CREATE INDEX idx_data_retention_log_table ON data_retention_log (table_name, purged_at);
CREATE INDEX idx_data_retention_log_record ON data_retention_log (record_id);
CREATE INDEX idx_data_retention_log_purged_at ON data_retention_log (purged_at);

CREATE RULE no_update_data_retention_log AS ON UPDATE TO data_retention_log DO INSTEAD NOTHING;
CREATE RULE no_delete_data_retention_log AS ON DELETE TO data_retention_log DO INSTEAD NOTHING;
```

---

## Eloquent Models

```php
namespace App\Models\Audit;

class AuditLog extends ImmutableModel
{
    protected $table = 'audit_log';

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state'  => 'array',
            'metadata'     => 'array',
            'actor_ip'     => 'string',
            'created_at'   => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Audit;

class ComplianceFlag extends ImmutableModel
{
    protected $table = 'compliance_flags';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Audit;

class DataRetentionLog extends ImmutableModel
{
    protected $table = 'data_retention_log';

    protected function casts(): array
    {
        return [
            'purged_at'  => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
```

---

## Retention Policy

| Table | Minimum Retention | Notes |
|---|---|---|
| `audit_log` | 10 years | No automated purge |
| `compliance_flags` | 10 years | No automated purge |
| `data_retention_log` | Permanent | Never purged — it is the purge log |

---

## Common Pitfalls

- **Do not call `AuditLog::create()` directly from controllers or services.** Always go through `AuditService::log()`. Direct creation bypasses sanitization and error handling.
- **Do not log raw encrypted field values** even in `before_state`/`after_state`. Fields marked `-- encrypted` in schema files must appear as `[REDACTED]` in audit records.
- **Do not wrap `AuditService::log()` calls in try/catch at the call site.** `AuditService` handles its own exceptions. Additional wrapping creates the illusion of a failed business operation when only the audit write failed.
- **Compliance flag corrections are new rows.** If a flag was misclassified, insert a new `compliance_flags` row with the correct information — do not attempt to update the original.
