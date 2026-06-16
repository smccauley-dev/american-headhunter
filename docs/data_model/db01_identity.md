# DB 1 — Identity & Authentication

**Connection:** `identity`
**Database:** `ah_identity`
**App User:** `ah_app`
**Server:** Dedicated high-security PostgreSQL instance — hardened OS, separate audit trail, quarterly key rotation
**Encryption Key:** Key A — rotated quarterly via Azure Key Vault
**Extensions:** `pgcrypto`, `uuid-ossp`
**RLS Enabled:** Yes — on `mfa_configurations`, `api_keys`, `background_check_results`

This database is the single source of truth for all user identities, credentials, roles, permissions, and authentication state. No other database stores user login credentials. All other databases reference users by `user_id UUID` — a cross-DB reference to `ah_identity.users.id`.

---

## Tables

### `users`

Core user record. Every human actor in the system has exactly one row here.

```sql
CREATE TABLE users (
    id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    email                   VARCHAR(255) NOT NULL,
    email_verified_at       TIMESTAMPTZ NULL,
    phone                   VARCHAR(20) NULL,
    phone_verified_at       TIMESTAMPTZ NULL,
    password_hash           TEXT        NOT NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'pending_verification'
                                CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification')),
    account_type            VARCHAR(20) NOT NULL
                                CHECK (account_type IN ('hunter', 'landowner', 'club', 'outfitter', 'consultant', 'seller', 'staff')),
    trust_score             SMALLINT    NOT NULL DEFAULT 50
                                CHECK (trust_score BETWEEN 0 AND 100),
    is_veteran              BOOLEAN     NOT NULL DEFAULT false,
    is_first_responder      BOOLEAN     NOT NULL DEFAULT false,
    discord_user_id         VARCHAR(30)  NULL,
    failed_login_attempts   SMALLINT    NOT NULL DEFAULT 0,
    locked_until            TIMESTAMPTZ NULL,
    last_login_at           TIMESTAMPTZ NULL,
    last_login_ip           INET        NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at              TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_users_email
    ON users (LOWER(email)) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_users_discord_user_id
    ON users (discord_user_id) WHERE discord_user_id IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_users_status       ON users (status);
CREATE INDEX idx_users_account_type ON users (account_type);
CREATE INDEX idx_users_deleted_at   ON users (deleted_at) WHERE deleted_at IS NOT NULL;

-- Trigger: update updated_at on row modification
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `password_hash` stores a bcrypt/argon2id hash — never a plaintext password
- `is_veteran` is set to `true` after a `VeteranVerification` record is approved — do not set directly
- `discord_user_id` is populated when the user connects Discord via OAuth (`oauth_connections` provider = 'discord') or during Discord community onboarding
- `trust_score` is maintained by `TrustScoreService` via `trust_score_events` — do not update directly
- `locked_until` is set by `AuthService` after `failed_login_attempts` exceeds threshold (configurable in DB 12 feature flags)
- `account_type` determines which portal the user is directed to at login

---

### `user_profiles`

Extended personal information. Separated from `users` to allow profile lookups without touching the security-sensitive users table.

```sql
CREATE TABLE user_profiles (
    id                          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    first_name                  VARCHAR(100) NULL,
    last_name                   VARCHAR(100) NULL,
    display_name                VARCHAR(100) NULL,
    avatar_document_id          UUID        NULL,  -- References DB 11 (Documents) documents.id
    bio                         TEXT        NULL,
    address_line1               TEXT        NULL,  -- encrypted (pgp_sym, identity key)
    address_line2               TEXT        NULL,  -- encrypted (pgp_sym, identity key)
    city                        TEXT        NULL,  -- encrypted (pgp_sym, identity key)
    county                      TEXT        NULL,  -- encrypted (pgp_sym, identity key)
    state_code                  CHAR(2)     NULL,
    zip_code                    VARCHAR(10) NULL,
    emergency_contact_name          TEXT    NULL,  -- encrypted (pgp_sym, identity key)
    emergency_contact_relationship  TEXT    NULL,  -- encrypted (pgp_sym, identity key)
    emergency_contact_phone         TEXT    NULL,  -- encrypted (pgp_sym, identity key)
    emergency_contact_email         TEXT    NULL,  -- encrypted (pgp_sym, identity key)
    date_of_birth               DATE        NULL,
    gender                      VARCHAR(20) NULL
                                    CHECK (gender IN ('male', 'female', 'non_binary', 'prefer_not_to_say') OR gender IS NULL),
    veteran_branch              VARCHAR(30) NULL
                                    CHECK (veteran_branch IN (
                                        'army','navy','air_force','marine_corps',
                                        'coast_guard','space_force','national_guard','reserves'
                                    ) OR veteran_branch IS NULL),
    veteran_service_start       DATE        NULL,  -- Date military service began
    veteran_service_end         DATE        NULL,  -- Date military service ended
    veteran_is_active           BOOLEAN      NOT NULL DEFAULT false,
    veteran_last_rank           VARCHAR(100) NULL,
    veteran_bio                 TEXT         NULL,
    first_responder_type        VARCHAR(30)  NULL
                                    CHECK (first_responder_type IN (
                                        'law_enforcement','fire','emt','search_rescue',
                                        'corrections','dispatch','other'
                                    ) OR first_responder_type IS NULL),
    first_responder_service_start DATE       NULL,  -- Date first responder service began
    first_responder_service_end   DATE       NULL,  -- Date first responder service ended
    first_responder_is_active   BOOLEAN      NOT NULL DEFAULT false,
    first_responder_last_rank   VARCHAR(100) NULL,
    first_responder_bio         TEXT         NULL,
    notification_preferences    JSONB       NOT NULL DEFAULT '{}',
    hunting_profile             JSONB       NOT NULL DEFAULT '{}',
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_user_profiles_user_id ON user_profiles (user_id);
CREATE        INDEX idx_user_profiles_state  ON user_profiles (state_code);

CREATE TRIGGER trg_user_profiles_updated_at
    BEFORE UPDATE ON user_profiles
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- No `deleted_at` — profile lifecycle is tied to `users.deleted_at` via CASCADE
- `address_line1`, `address_line2`, `city`, `county`, and all `emergency_contact_*` columns are encrypted at rest via `pgp_sym_encrypt` (identity key) and stored as base64 TEXT. The `UserProfile` model's `HasEncryptedFields` trait encrypts/decrypts transparently — never read these raw, and never log their values. `state_code` and `zip_code` remain plaintext (indexed, used for filtering).
- `avatar_document_id` is a cross-DB reference: fetch the URL via `DocumentService::getUrl($avatarDocumentId)`
- `notification_preferences` structure: `{"email": true, "sms": false, "push": true, "in_app": true}`
- `hunting_profile` structure: `{"species": ["whitetail", "turkey"], "methods": ["rifle", "bow"], "experience_years": 10}`

---

### `roles`

System-defined roles. The 8 canonical roles are seeded at installation and are not user-creatable.

```sql
CREATE TABLE roles (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    name         VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description  TEXT        NULL,
    is_system    BOOLEAN     NOT NULL DEFAULT false,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_roles_name ON roles (name);
```

**Seed data:**

| name | display_name | is_system |
|---|---|---|
| `hunter` | Hunter | true |
| `landowner` | Landowner | true |
| `club_admin` | Club Administrator | true |
| `outfitter` | Outfitter | true |
| `consultant` | Hunting Consultant | true |
| `seller` | Equipment Seller | true |
| `staff` | Platform Staff | true |
| `super_admin` | Super Administrator | true |

---

### `permissions`

Granular permission strings used by the authorization system. Permissions are seeded and managed by staff, not end users.

```sql
CREATE TABLE permissions (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    description  TEXT        NULL,
    category     VARCHAR(50) NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_permissions_name ON permissions (name);
CREATE        INDEX idx_permissions_category ON permissions (category);
```

**Example permission names by category:**

| Category | Permission Names |
|---|---|
| `lease` | `lease.view`, `lease.apply`, `lease.manage`, `lease.terminate` |
| `property` | `property.view`, `property.create`, `property.manage`, `property.list` |
| `harvest` | `harvest.log`, `harvest.view_own`, `harvest.view_all` |
| `billing` | `billing.view_own`, `billing.manage`, `billing.payout` |
| `auction` | `auction.bid`, `auction.create`, `auction.manage` |
| `admin` | `admin.users`, `admin.reports`, `admin.platform` |

---

### `role_permissions`

Pivot table assigning permissions to roles.

```sql
CREATE TABLE role_permissions (
    role_id       UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
    permission_id UUID NOT NULL REFERENCES permissions (id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE INDEX idx_role_permissions_permission_id ON role_permissions (permission_id);
```

---

### `user_roles`

Pivot table assigning roles to users. A user may have multiple roles (e.g., a landowner who is also a hunter).

```sql
CREATE TABLE user_roles (
    user_id          UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role_id          UUID        NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
    granted_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    granted_by_user_id UUID     NULL,  -- References users.id (self-referential, nullable for system grants)
    PRIMARY KEY (user_id, role_id)
);

CREATE INDEX idx_user_roles_role_id ON user_roles (role_id);
```

---

### `mfa_configurations`

One row per MFA method per user. A user may have multiple methods configured (e.g., TOTP + SMS backup).

```sql
CREATE TABLE mfa_configurations (
    id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                 UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    method                  VARCHAR(10) NOT NULL
                                CHECK (method IN ('totp', 'sms', 'email')),
    is_enabled              BOOLEAN     NOT NULL DEFAULT false,
    secret_encrypted        TEXT        NULL,  -- encrypted (pgp_sym_encrypt) — TOTP secret or null
    verified_at             TIMESTAMPTZ NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_mfa_configurations_user_method ON mfa_configurations (user_id, method);
CREATE        INDEX idx_mfa_configurations_user_id    ON mfa_configurations (user_id);

CREATE TRIGGER trg_mfa_configurations_updated_at
    BEFORE UPDATE ON mfa_configurations
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE mfa_configurations ENABLE ROW LEVEL SECURITY;

CREATE POLICY mfa_own_user_only ON mfa_configurations
    FOR ALL TO ah_app
    USING (user_id = current_setting('app.current_user_id')::UUID);
```

**Notes:**
- `secret_encrypted` is the TOTP shared secret, encrypted via `pgp_sym_encrypt` with Key A
- Recovery codes live in `user_recovery_codes` (DB 1), keyed to the user account, not this row. Each code is bcrypt-hashed and single-use. Column `backup_codes_encrypted` was dropped (migration `2026_06_10_000002`).
- Never log or return the decrypted secret

---

### `mfa_challenges`

Temporary challenge records created when a user initiates an MFA verification step. Expire after a short TTL.

```sql
CREATE TABLE mfa_challenges (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    method      VARCHAR(10) NOT NULL CHECK (method IN ('totp', 'sms', 'email')),
    code_hash   TEXT        NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ NULL,
    ip_address  INET        NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_mfa_challenges_user_id    ON mfa_challenges (user_id);
CREATE INDEX idx_mfa_challenges_expires_at ON mfa_challenges (expires_at);
```

**Notes:**
- `code_hash` is a bcrypt hash of the one-time code — never store plaintext codes
- Expired records are purged nightly by `PurgeMfaChallengesJob`

---

### `password_reset_tokens`

```sql
CREATE TABLE password_reset_tokens (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash  TEXT        NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ NULL,
    ip_address  INET        NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_password_reset_tokens_user_id    ON password_reset_tokens (user_id);
CREATE INDEX idx_password_reset_tokens_expires_at ON password_reset_tokens (expires_at);
```

---

### `email_verification_tokens`

```sql
CREATE TABLE email_verification_tokens (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    email       VARCHAR(255) NOT NULL,
    token_hash  TEXT        NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_email_verification_tokens_user_id ON email_verification_tokens (user_id);
CREATE INDEX idx_email_verification_tokens_email   ON email_verification_tokens (email);
```

---

### `oauth_connections`

OAuth provider connections for social login (Google, Apple, Facebook, Discord).

```sql
CREATE TABLE oauth_connections (
    id                       UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                  UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    provider                 VARCHAR(20) NOT NULL
                                 CHECK (provider IN ('google', 'apple', 'facebook', 'discord')),
    provider_user_id         VARCHAR(255) NOT NULL,
    provider_email           VARCHAR(255) NULL,
    access_token_encrypted   TEXT        NULL,  -- encrypted (pgp_sym_encrypt)
    refresh_token_encrypted  TEXT        NULL,  -- encrypted (pgp_sym_encrypt)
    token_expires_at         TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_oauth_connections_provider_user
    ON oauth_connections (provider, provider_user_id);
CREATE INDEX idx_oauth_connections_user_id ON oauth_connections (user_id);

CREATE TRIGGER trg_oauth_connections_updated_at
    BEFORE UPDATE ON oauth_connections
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

---

### `api_keys`

API keys for the public API (behind `public_api` feature flag). Keys are shown once at creation; only the hash is stored.

```sql
CREATE TABLE api_keys (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    key_hash    TEXT        NOT NULL,
    key_prefix  CHAR(8)     NOT NULL,
    scopes      JSONB       NOT NULL DEFAULT '[]',
    last_used_at TIMESTAMPTZ NULL,
    expires_at  TIMESTAMPTZ NULL,
    revoked_at  TIMESTAMPTZ NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at  TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_api_keys_key_hash ON api_keys (key_hash);
CREATE        INDEX idx_api_keys_user_id  ON api_keys (user_id);
CREATE        INDEX idx_api_keys_deleted_at ON api_keys (deleted_at) WHERE deleted_at IS NOT NULL;
```

**RLS Policy:**
```sql
ALTER TABLE api_keys ENABLE ROW LEVEL SECURITY;

CREATE POLICY api_keys_own_user ON api_keys
    FOR ALL TO ah_app
    USING (user_id = current_setting('app.current_user_id')::UUID);
```

---

### `background_check_results`

Checkr background check results. Required for landowners before listing a property and optionally for hunters.

```sql
CREATE TABLE background_check_results (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id               UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    provider              VARCHAR(50) NOT NULL DEFAULT 'checkr',
    provider_report_id    VARCHAR(255) NOT NULL,
    status                VARCHAR(20) NOT NULL
                              CHECK (status IN ('pending', 'clear', 'consider', 'suspended', 'dispute')),
    report_type           VARCHAR(50) NOT NULL,
    initiated_at          TIMESTAMPTZ NOT NULL,
    completed_at          TIMESTAMPTZ NULL,
    expires_at            TIMESTAMPTZ NULL,  -- typically 2 years from completed_at
    raw_result_encrypted  TEXT        NULL,  -- encrypted (pgp_sym_encrypt) — full Checkr JSON response
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_background_check_results_user_id ON background_check_results (user_id);
CREATE INDEX idx_background_check_results_status  ON background_check_results (status);
CREATE INDEX idx_background_check_results_expires ON background_check_results (expires_at);

CREATE TRIGGER trg_background_check_results_updated_at
    BEFORE UPDATE ON background_check_results
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE background_check_results ENABLE ROW LEVEL SECURITY;

CREATE POLICY bgcheck_staff_and_own ON background_check_results
    FOR SELECT TO ah_app
    USING (
        user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `raw_result_encrypted` contains the full Checkr API response — never log it
- `expires_at` is typically `completed_at + INTERVAL '2 years'`; a job re-triggers checks before expiry
- A Checkr webhook updates `status` and `completed_at` via `ProcessBackgroundCheckWebhookJob`

---

### `ofac_screening_results`

OFAC (Office of Foreign Assets Control) sanctions screening. All users are screened at signup and periodically thereafter.

```sql
CREATE TABLE ofac_screening_results (
    id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                 UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    status                  VARCHAR(10) NOT NULL
                                CHECK (status IN ('clear', 'match', 'pending')),
    screened_at             TIMESTAMPTZ NOT NULL,
    next_screening_at       TIMESTAMPTZ NULL,
    match_details_encrypted TEXT        NULL,  -- encrypted (pgp_sym_encrypt) — null if clear
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only — no updated_at, no deleted_at
CREATE INDEX idx_ofac_screening_results_user_id          ON ofac_screening_results (user_id);
CREATE INDEX idx_ofac_screening_results_next_screening   ON ofac_screening_results (next_screening_at)
    WHERE next_screening_at IS NOT NULL;
CREATE INDEX idx_ofac_screening_results_status           ON ofac_screening_results (status);
```

**Notes:**
- Append-only log — never update or delete rows. Each screening creates a new row.
- `OfacService` queries this table for the latest row per user: `ORDER BY screened_at DESC LIMIT 1`
- A `match` status triggers immediate account suspension and staff notification
- `match_details_encrypted` is only decrypted by staff with elevated permissions

---

### `trust_score_events`

Append-only log of all trust score changes. The current trust score on `users.trust_score` is the authoritative value; this table is the audit trail.

```sql
CREATE TABLE trust_score_events (
    id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    event_type  VARCHAR(60) NOT NULL
                    CHECK (event_type IN (
                        'background_check_passed',
                        'background_check_failed',
                        'lease_completed',
                        'lease_terminated_early',
                        'dispute_raised',
                        'dispute_resolved_for_user',
                        'dispute_resolved_against_user',
                        'verified_landowner',
                        'email_verified',
                        'phone_verified',
                        'id_verified',
                        'ofac_cleared',
                        'ofac_match',
                        'positive_review',
                        'negative_review',
                        'account_suspended',
                        'admin_adjustment'
                    )),
    delta       SMALLINT    NOT NULL,  -- positive or negative change
    score_after SMALLINT    NOT NULL,
    metadata    JSONB       NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only — no updated_at, no deleted_at
CREATE INDEX idx_trust_score_events_user_id    ON trust_score_events (user_id);
CREATE INDEX idx_trust_score_events_event_type ON trust_score_events (event_type);
CREATE INDEX idx_trust_score_events_created_at ON trust_score_events (created_at);
```

---

### `login_history`

Append-only record of all login attempts (successful and failed). Used for security auditing and account lockout logic.

```sql
CREATE TABLE login_history (
    id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id         UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    ip_address      INET        NOT NULL,
    user_agent      TEXT        NULL,
    success         BOOLEAN     NOT NULL,
    failure_reason  VARCHAR(50) NULL
                        CHECK (failure_reason IN (
                            'wrong_password',
                            'account_locked',
                            'account_suspended',
                            'account_banned',
                            'mfa_failed',
                            'mfa_expired',
                            'not_found'
                        ) OR failure_reason IS NULL),
    mfa_used        BOOLEAN     NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only — no updated_at, no deleted_at
CREATE INDEX idx_login_history_user_id    ON login_history (user_id);
CREATE INDEX idx_login_history_ip_address ON login_history (ip_address);
CREATE INDEX idx_login_history_created_at ON login_history (created_at);
CREATE INDEX idx_login_history_success    ON login_history (success);
```

---

### `guardian_relationships`

Links minor users to their guardian. Consent is scoped and revocable.

```sql
CREATE TABLE guardian_relationships (
    id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    minor_user_id       UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    guardian_user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    consent_granted_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    consent_expires_at  TIMESTAMPTZ NULL,
    revoked_at          TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_guardian_relationships_pair UNIQUE (minor_user_id, guardian_user_id)
);

CREATE INDEX idx_guardian_relationships_minor    ON guardian_relationships (minor_user_id);
CREATE INDEX idx_guardian_relationships_guardian ON guardian_relationships (guardian_user_id);
```

---

### `identity_verifications`

ID verification records (ID.me, Stripe Identity, or manual staff review). A user may have multiple records across verification types.

```sql
CREATE TABLE identity_verifications (
    id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id              UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    provider             VARCHAR(50)  NOT NULL
                             CHECK (provider IN ('id_me', 'stripe_identity', 'manual')),
    verification_type    VARCHAR(30)  NOT NULL
                             CHECK (verification_type IN ('identity', 'veteran', 'age')),
    status               VARCHAR(20)  NOT NULL
                             CHECK (status IN ('pending', 'verified', 'failed', 'expired')),
    provider_session_id  VARCHAR(255) NULL,
    verified_at          TIMESTAMPTZ  NULL,
    expires_at           TIMESTAMPTZ  NULL,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_identity_verifications_user_id ON identity_verifications (user_id);
CREATE INDEX idx_identity_verifications_status  ON identity_verifications (status);
```

---

### `veteran_verifications`

Tracks DD-214 upload or ID.me veteran credential verification. Approved records unlock veteran pricing tier.

```sql
CREATE TABLE veteran_verifications (
    id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id              UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    method               VARCHAR(30)  NOT NULL
                             CHECK (method IN ('id_me', 'dd214_upload')),
    status               VARCHAR(20)  NOT NULL
                             CHECK (status IN ('pending', 'approved', 'rejected')),
    document_id          UUID         NULL,  -- References DB 11 (Documents) documents.id (DD-214 file)
    id_me_uuid           VARCHAR(255) NULL,
    verified_at          TIMESTAMPTZ  NULL,
    reviewed_by_user_id  UUID         NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_veteran_verifications_user_id ON veteran_verifications (user_id);
CREATE INDEX idx_veteran_verifications_status  ON veteran_verifications (status);
```

**Notes:**
- When status transitions to `approved`, `UserService` sets `users.is_veteran = true` and triggers a trust score event (`verified_landowner`).
- `document_id` cross-references DB 11 — never store file contents here.

---

### `consent_log`

Append-only CCPA and ToS consent capture. A new row is written every time a user grants or revokes consent of any type.

```sql
CREATE TABLE consent_log (
    id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id      UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    consent_type VARCHAR(50) NOT NULL
                     CHECK (consent_type IN (
                         'terms_of_service',
                         'privacy_policy',
                         'ccpa',
                         'marketing_emails',
                         'sms_notifications'
                     )),
    granted      BOOLEAN     NOT NULL,
    version      VARCHAR(20) NOT NULL,  -- e.g. '2026-01-01'
    ip_address   INET        NULL,
    user_agent   TEXT        NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Append-only — no updated_at, no deleted_at
CREATE INDEX idx_consent_log_user_id      ON consent_log (user_id);
CREATE INDEX idx_consent_log_consent_type ON consent_log (consent_type);
CREATE INDEX idx_consent_log_created_at   ON consent_log (created_at);
```

**Notes:**
- To determine current consent state for a user: query `ORDER BY created_at DESC LIMIT 1` per `consent_type`.
- `version` is the effective date of the ToS/privacy policy version the user agreed to.

---

## Eloquent Models

### `App\Models\Identity\User`

```php
<?php

namespace App\Models\Identity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $connection = 'identity';
    protected $table      = 'users';

    public $timestamps    = false;  // Managed by PostgreSQL triggers
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'email',
        'phone',
        'password_hash',
        'status',
        'account_type',
        'trust_score',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'phone_verified_at'    => 'datetime',
            'locked_until'         => 'datetime',
            'last_login_at'        => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
        ];
    }

    // Cross-DB: fetch from DB 11 via DocumentService
    public function getAvatarUrl(): ?string
    {
        $profile = $this->profile;
        if (! $profile?->avatar_document_id) {
            return null;
        }
        return app(\App\Services\Documents\DocumentService::class)
            ->getUrl($profile->avatar_document_id);
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
                    ->withPivot('granted_at', 'granted_by_user_id');
    }
}
```

### `App\Models\Identity\UserProfile`

```php
protected $connection = 'identity';
protected $table      = 'user_profiles';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'date_of_birth'              => 'date',
        'notification_preferences'   => 'array',
        'created_at'                 => 'datetime',
        'updated_at'                 => 'datetime',
    ];
}
```

### `App\Models\Identity\Role`

```php
protected $connection = 'identity';
protected $table      = 'roles';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'is_system'  => 'boolean',
        'created_at' => 'datetime',
    ];
}
```

### `App\Models\Identity\MfaConfiguration`

```php
protected $connection = 'identity';
protected $table      = 'mfa_configurations';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

// Never include secret_encrypted in fillable
protected $fillable   = ['user_id', 'method', 'is_enabled', 'verified_at'];
protected $hidden     = ['secret_encrypted'];
```

### `App\Models\Identity\BackgroundCheckResult`

```php
protected $connection = 'identity';
protected $table      = 'background_check_results';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected $hidden     = ['raw_result_encrypted'];

protected function casts(): array
{
    return [
        'initiated_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'expires_at'    => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];
}
```

### `App\Models\Identity\LoginHistory`

```php
protected $connection = 'identity';
protected $table      = 'login_history';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

// Append-only — no delete() override needed, but never call delete() on these records
protected function casts(): array
{
    return [
        'success'    => 'boolean',
        'mfa_used'   => 'boolean',
        'created_at' => 'datetime',
    ];
}
```

### `App\Models\Identity\TrustScoreEvent`

```php
protected $connection = 'identity';
protected $table      = 'trust_score_events';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected function casts(): array
{
    return [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];
}
```

---

## Service Notes

- **`AuthService`** — handles login, logout, lockout logic, and MFA challenge creation. Lives at `App\Services\Auth\AuthService`.
- **`MfaService`** — generates and verifies MFA codes, manages backup codes. Lives at `App\Services\Auth\MfaService`.
- **`UserService`** — CRUD for users and profiles; cross-DB user lookup by ID. Lives at `App\Services\Identity\UserService`.
- **`VerificationService`** — email and phone verification flows. Lives at `App\Services\Identity\VerificationService`.
- **`OfacService`** — triggers OFAC screening, stores results, triggers suspension on match. Lives at `App\Services\Identity\OfacService`.
- **`TrustScoreService`** — records trust score events and atomically updates `users.trust_score`. Lives at `App\Services\Identity\TrustScoreService`.

User lookups by other services (e.g., `LeaseService` needing to display lessee info) go through `UserService::find(uuid)`, which caches results in Valkey Cluster 2 with key `user:{uuid}`.
