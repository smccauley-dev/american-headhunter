# Deployment Pre-Production Checklist

Items that must be resolved before production launch. None of these affect development
or test correctness — they are stubs or deferred decisions that are safe locally but
would be security gaps or silent failures in production.

---

## 1. Stubbed Implementations

Three components ship as no-ops and must be replaced before production.

---

### 1a. SMS OTP Sender

**Status:** No real SMS provider wired. Every SMS call logs a line to `laravel.log` and returns.

**Files:**
- Interface: `app/Contracts/Sms/SmsDriver.php`
- Stub: `app/Services/Sms/StubSmsDriver.php`
- Binding: `app/Providers/AppServiceProvider.php` — `$this->app->bind(SmsDriver::class, StubSmsDriver::class)`

**Impact:** SMS MFA (`mfaSend` + `mfaVerify` with `method=sms`) silently succeeds in code
but never sends a message. Users who enroll SMS MFA in production will never receive codes.

**Done when:**
1. A real driver class (e.g., `TwilioSmsDriver`) implements `SmsDriver::send(string $phone, string $message): void`
2. The binding in `AppServiceProvider::register()` is switched from `StubSmsDriver` to the real driver
3. Provider credentials are set in `.env` / Key Vault and documented in `docs/laravel/env.example`

No other files need to change — the interface is already consumed correctly by `SmsMfaMethod`.

#### Re-disabling SMS after it has been live — not a quiet toggle

The platform uses **Option A** for factor disablement: disabling a factor platform-wide
blocks new enrollments only. Users who are already enrolled can still use that factor to
verify. This is intentional — it prevents surprise lockouts when toggling flags.

For SMS this means: once SMS has gone live and users have enrolled in it, turning
`is_enabled = false` again in `mfa_factor_settings` does **not** stop enrolled users
from being routed to the SMS verification flow. They will be prompted for an SMS code,
but with the stub driver re-bound (or the provider credentials removed), they will never
receive one. Every enrolled SMS user will be locked out on their next login.

**Before re-disabling a previously-live SMS factor:**
1. Query `mfa_configurations` for all users with `method = 'sms'` and `is_enabled = true`.
2. Either:
   a. Notify them and give them time to switch to TOTP or email OTP before the cutoff, or
   b. Administratively reset their MFA (`UserService::resetMfa()`) so they are forced to
      re-enroll from scratch on next login.
3. Once no enrolled SMS users remain, disable the platform toggle safely.

This is a no-op concern today — SMS is seeded as `disabled` and nobody can have enrolled
in it. The runbook above only becomes relevant after SMS has been live with real users.

---

### 1b. Document Virus Scanner

**Status:** No-op. Dispatched after every document upload; marks the document as `ready` immediately without scanning.

**File:** `app/Jobs/Documents/ScanDocumentForViruses.php`

```php
public function handle(DocumentService $service): void
{
    // TODO: integrate with ClamAV or cloud AV scanning service.
    // For now, mark the document as ready (clean) immediately.
    $service->markReady($this->documentId);
}
```

**Impact:** Every uploaded file — including identity documents (driver's licenses, hunting
licenses) from public users — is marked clean without inspection. A malicious file upload
passes directly to storage and becomes downloadable.

**Done when:**
1. `handle()` calls a real AV scanner (ClamAV via clamd socket, or a cloud service such as
   Cloudmersive Virus Scan, AWS Macie, or equivalent)
2. On `clean` result: `$service->markReady($this->documentId)`
3. On `infected` result: `$service->markInfected($this->documentId)` — document status set to
   `failed`, file quarantined or deleted from storage, owner notified
4. On scanner error: job fails and retries (the 3-retry / 120s timeout already handles this);
   document stays in `processing` state until resolved

The `DocumentService::markReady()` method already exists. Add `markInfected()` alongside it.

---

### 1c. QR Code Image Generator

**Status:** No-op. The job is dispatched when a QR code record is created but `handle()` does nothing.

**File:** `app/Jobs/Documents/GenerateQrCodeImage.php`

```php
public function handle(): void
{
    // TODO: generate QR code image and store it.
}
```

**Impact:** QR codes exist as database records (`qr_codes` table in DB 11) but have no
associated image file. Any UI that renders a QR code will have a broken image.

**Done when:**
1. `handle()` reads the QR code record from `qr_codes` where `id = $this->qrCodeId`
2. Generates a PNG/SVG using a library (e.g., `endroid/qr-code` or `bacon/bacon-qr-code`)
3. Uploads the image to blob storage (Garage / Azure Blob via `DocumentService` or `StorageService`)
4. Updates the `qr_codes` row with the storage path / public URL
5. The 3-retry / 60s timeout already set on the job is sufficient for image generation

---

## 2. Encryption Keys → Vault

### Background

Five database connections use pgcrypto (`pgp_sym_encrypt` / `pgp_sym_decrypt`) for
field-level encryption of sensitive data. The encryption key per connection is injected at
boot time by `DatabaseServiceProvider` from environment variables and placed into
`config('database.connections.<connection>.options.encryption_key')`.

Encrypted connections and their env variables:

| Connection  | Env variable                | Encrypted fields (examples)                        |
|-------------|-----------------------------|----------------------------------------------------|
| `identity`  | `ENCRYPTION_KEY_IDENTITY`   | `mfa_configurations.secret_encrypted` (TOTP keys) |
| `billing`   | `ENCRYPTION_KEY_BILLING`    | payment method metadata                            |
| `lease`     | `ENCRYPTION_KEY_LEASE`      | `lease_applications` PII fields                   |
| `documents` | `ENCRYPTION_KEY_DOCUMENTS`  | document metadata if marked sensitive              |

### Current dev state

During development, three keys were blank in `.env`, causing `pgp_sym_encrypt` to silently
store `NULL` (PostgreSQL returns NULL when the key argument is NULL). This was discovered
when TOTP MFA always failed — decrypting a NULL ciphertext returns NULL, so the stored
secret was unreadable. Keys were set to randomly generated base64 strings to unblock
development:

```
ENCRYPTION_KEY_IDENTITY=XYOyG8evIgPOZYxIb3C3NQku0/Z5ZK0hQkBdABK53B4=   ← DEV ONLY
ENCRYPTION_KEY_BILLING=kPV6spnDd7ysY+K/Md3qUR9Vf2RAz578o/hDlzdgixA=    ← DEV ONLY
ENCRYPTION_KEY_DOCUMENTS=8FSnbQXGKaa+mxQwviunAAYg3MUl0gBAuTbWZUrcScw=  ← DEV ONLY
ENCRYPTION_KEY_LEASE=NdKpOTReTgIcz1M9DDYU/SxjiOcKWNnVY3hK/Bdu0No=      ← set earlier
```

**These keys must never reach production and must never be shared.**

### Key invariant — read this before the production cutover

**Data encrypted under one key CANNOT be decrypted under a different key.**

pgcrypto's `pgp_sym_encrypt` derives the actual cipher key from the passphrase using
OpenPGP S2K (string-to-key). Changing `ENCRYPTION_KEY_IDENTITY` in `.env` after data has
been written means every row with an encrypted column becomes permanently unreadable without
a re-encryption step.

**Consequence for the cutover:**

Option A — Clean cutover (preferred): Production keys are finalized in HashiCorp Vault
**before any real user data is written**. The app starts with production keys from day one.
No re-encryption needed. This is the only safe path if you can control the go-live timeline.

Option B — Re-encryption migration (use only if data already exists under dev keys):
For each encrypted column on each affected table, run a migration that reads each row,
decrypts with the old key, re-encrypts with the new key, and updates in place:

```sql
UPDATE mfa_configurations
SET secret_encrypted = pgp_sym_encrypt(
    pgp_sym_decrypt(secret_encrypted::bytea, '<old_key>'),
    '<new_key>'
)
WHERE secret_encrypted IS NOT NULL;
```

This must be done per-table, per-column, in a maintenance window with the app offline
(or in a background job with the connection in read-only mode). **Never run the re-encryption
query with both old and new keys visible in a shared shell history or log.**

### Production key source

`DatabaseServiceProvider::boot()` reads from `env()`. For production (Azure Container Apps),
`env()` values come from Azure Key Vault references injected as environment variables.
No code changes are needed — only infrastructure configuration.

The provider file is at `app/Providers/DatabaseServiceProvider.php`.

---

## 3. Migration Run Order

All 14 databases must be migrated in dependency order. Use:

```bash
php artisan migrate:all           # runs all connections in declared order
php artisan migrate:single <name> # run one connection by name
```

### Identity (DB 1) — run first; auth system depends on it

The API authentication layer (`personal_access_tokens`, `mfa_configurations`,
`user_recovery_codes`) is on this connection. No other connection should be migrated
before identity is healthy.

| Migration file | Notes |
|---|---|
| `2026_05_24_000001_create_extensions.php` | pgcrypto, uuid-ossp — must succeed before all others |
| `2026_05_24_000002_create_users_table.php` | |
| `2026_05_24_000003_create_user_profiles_table.php` | |
| `2026_05_24_000004_create_roles_and_permissions_tables.php` | |
| `2026_05_24_000005_create_mfa_tables.php` | Creates `mfa_configurations` + `mfa_challenges`; auth depends on this |
| `2026_05_24_000006_create_token_tables.php` | |
| `2026_05_24_000007_create_oauth_connections_table.php` | |
| `2026_05_24_000008_create_api_keys_table.php` | |
| `2026_05_24_000009_create_verification_tables.php` | |
| `2026_05_24_000010_create_append_only_tables.php` | |
| `2026_05_24_000011_create_guardian_relationships_table.php` | |
| `2026_05_24_000012_create_rls_policies.php` | RLS policies reference all identity tables — must run after them |
| `2026_05_24_000013_create_personal_access_tokens_table.php` | Sanctum PATs live on this connection, not the default DB |
| `2026_05_24_000014_create_job_tables.php` | |
| `2026_05_31_000001_fix_rls_rename_current_role_to_user_role.php` | |
| `2026_06_06_000015_create_hunter_credentials_table.php` | |
| `2026_06_06_000016_create_guest_hunters_table.php` | |
| `2026_06_07_000017_create_user_admin_notes_table.php` | |
| `2026_06_07_000018_add_veteran_fields_to_user_profiles.php` | |
| `2026_06_07_000019_replace_veteran_service_years_with_start_end.php` | |
| `2026_06_07_000020_add_first_responder_and_veteran_active_fields.php` | |
| `2026_06_08_000001_add_back_photo_columns_to_hunter_credentials.php` | |
| `2026_06_09_000001_create_user_legal_acceptances_table.php` | |
| `2026_06_10_000001_create_user_recovery_codes_table.php` | Recovery codes table — MFA recovery endpoint depends on this |

### Documents (DB 11) — run after identity

| Migration file | Notes |
|---|---|
| `2026_06_06_000001_create_documents_extensions_and_trigger.php` | Extensions + updated_at trigger |
| `2026_06_06_000002_create_documents_table.php` | Defines `chk_documents_storage_provider CHECK (storage_provider IN ('garage', 'azure_blob'))` |
| `2026_06_06_000003_create_document_thumbnails_table.php` | |
| `2026_06_06_000004_create_esignature_tables.php` | |
| `2026_06_06_000005_create_qr_codes_table.php` | |
| `2026_06_06_000006_create_print_jobs_table.php` | |
| `2026_06_06_000007_expand_document_type_constraint.php` | ⚠️ See note below |
| `2026_06_06_000008_add_unattached_document_status.php` | ⚠️ See note below |

### Lease (DB 3) — run after identity

| Migration file | Notes |
|---|---|
| `2026_06_06_000001_create_lease_extensions_and_trigger.php` | |
| `2026_06_06_000002_create_lease_applications_table.php` | |
| `2026_06_06_000003_create_leases_table.php` | |
| `2026_06_06_000004_create_lease_hunters_table.php` | |
| `2026_06_06_000005_create_clubs_tables.php` | |
| `2026_06_06_000006_create_check_ins_table.php` | |
| `2026_06_06_000007_create_lease_renewals_table.php` | |
| `2026_06_06_000008_create_signature_events_table.php` | |
| `2026_06_06_000009_create_esignature_requests_table.php` | |
| `2026_06_06_000010_create_lease_notes_table.php` | |
| `2026_06_06_000011_create_lease_rls_policies.php` | |
| `2026_06_06_000012_create_lease_application_hunters_table.php` | |
| `2026_06_06_000013_add_messaging_to_applications.php` | |
| `2026_06_06_000014_create_lease_application_review_history_table.php` | |
| `2026_06_06_000015_add_listing_snapshot_to_lease_applications.php` | |
| `2026_06_07_000001_encrypt_application_pii.php` | Adds encrypted PII columns; requires `ENCRYPTION_KEY_LEASE` set |
| `2026_06_08_000001_add_back_photo_columns_to_lease_application_hunters.php` | |

### All other connections

Property (DB 2), Commerce (DB 6), Communications (DB 7), Wildlife (DB 5), Analytics (DB 8),
Audit (DB 9), Incidents (DB 10), Platform (DB 12), Geospatial (DB 13), Research (DB 14)
have no dependency on the above and can run in parallel with lease, after identity.
Billing (DB 4) has no migrations yet.

---

### ⚠️ Migrations that can fail against existing production data

#### `documents/2026_06_06_000007_expand_document_type_constraint.php`

This migration drops `chk_documents_type` and recreates it with two new values
(`driver_license`, `hunting_license`):

```sql
ALTER TABLE documents
    DROP CONSTRAINT chk_documents_type,
    ADD CONSTRAINT chk_documents_type
        CHECK (document_type IN (
            'photo', 'video', 'pdf', 'contract',
            'id_document', 'driver_license', 'hunting_license',
            'other'
        ));
```

**Failure scenario:** If production rows contain a `document_type` value not in the new list
(e.g., a value written by an older code version or a manual DB operation), `ADD CONSTRAINT`
will fail and the migration rolls back, leaving the table without either constraint.

**Pre-migration check:**
```sql
SELECT DISTINCT document_type FROM documents
WHERE document_type NOT IN (
    'photo','video','pdf','contract','id_document',
    'driver_license','hunting_license','other'
);
-- Must return 0 rows before running the migration.
```

If rows are returned, update or delete them before migrating.

---

#### `documents/2026_06_06_000008_add_unattached_document_status.php` — HIGHEST-RISK ITEM

**This is a deployment sequencing requirement, not just a data-shape concern.**

The code that writes `status = 'unattached'` (the document upload flow) shipped in the same
change set as this migration. The migration adds `'unattached'` to the `chk_documents_status`
CHECK constraint. If the app deploys before the migration runs, any document upload attempt
will throw a PostgreSQL CHECK violation and return a 500 to the user.

**Rule: this migration MUST run before the app version that depends on it goes live.**

The safe deploy sequence is:
1. Run `php artisan migrate:single documents` (adds `'unattached'` to the constraint)
2. Deploy the new app container

Do not reverse the order. Do not deploy code and plan to migrate later.

The migration itself is safe against existing data — adding a new allowed value to a CHECK
constraint never invalidates existing rows. The constraint DROP+ADD is atomic in PostgreSQL.

**Verification before deploy:**
```sql
-- Confirm the migration has run (constraint now includes 'unattached')
SELECT pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conname = 'chk_documents_status';
-- Expected output contains: 'unattached'
```

---

#### `documents/2026_06_06_000002_create_documents_table.php` — storage_provider

The table is created with:

```sql
CONSTRAINT chk_documents_storage_provider
    CHECK (storage_provider IN ('garage', 'azure_blob'))
```

This is not a migration that modifies an existing table, so it cannot fail against existing
rows. However, it becomes relevant during the Garage → Azure Blob migration (see
`docs/storage_strategy.md` and `docs/azure_migration.md`): any future migration that
changes this constraint or the default value must run a pre-check verifying no rows hold
a `storage_provider` value outside the new allowed set, using the same pattern above.

When migrating from Garage to Azure: the `DEFAULT 'garage'` must be changed to
`DEFAULT 'azure_blob'` (or made conditional) and existing rows bulk-updated before the
migration runs. Do not flip the default and leave existing rows pointing at `garage` while
the storage backend has moved — the app will attempt to serve files from a decommissioned
endpoint.

---

*Last updated: 2026-06-10 — covers migrations and stubs introduced through Step 4 (mobile API auth).*
