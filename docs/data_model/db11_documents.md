# DB 11 — Documents & Media

**Server:** Standard PostgreSQL
**Encryption Key:** Key K — rotated annually
**Laravel Connection:** `documents`
**Database:** `ah_documents`
**DB User:** `ah_app`
**Access:** Application document service, e-signature webhook processor, virus scanner service, video transcoding worker, QR code generation job

---

## Purpose

Metadata for all files generated or uploaded on the platform. Actual file bytes live in object storage (Garage on-prem, Azure Blob in cloud — see `docs/storage_strategy.md`). This database holds the metadata, processing state, virus scan status, and storage keys. Also owns the e-signature request registry, QR code registry, and print job queue.

**The `storage_key` column is the canonical reference to the object in storage.** When an object is deleted from storage, set `status = 'deleted'` on the document row — never hard-delete the metadata row.

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

### documents
The master file metadata table. One row per uploaded or generated file. Status tracks the file through its lifecycle: upload → virus scan → processing → ready.

```sql
CREATE TABLE documents (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    owner_user_id       UUID NOT NULL,               -- References DB 1 (Identity) users.id
    document_type       VARCHAR(20) NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'pending',
    original_filename   VARCHAR(500),
    mime_type           VARCHAR(100),
    size_bytes          BIGINT,
    storage_bucket      VARCHAR(100),                -- Bucket/container name in the storage provider
    storage_key         TEXT,                        -- Full object key path in the bucket
    storage_provider    VARCHAR(20) NOT NULL DEFAULT 'garage',
    width_px            INT,                         -- Photos only
    height_px           INT,                         -- Photos only
    duration_seconds    INT,                         -- Videos only
    checksum_sha256     CHAR(64),                    -- SHA-256 of the file bytes — set on upload, verified on serve
    is_public           BOOLEAN NOT NULL DEFAULT false,
    virus_scan_status   VARCHAR(20) NOT NULL DEFAULT 'pending',
    virus_scanned_at    TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at          TIMESTAMPTZ,

    CONSTRAINT chk_documents_type
        CHECK (document_type IN ('photo', 'video', 'pdf', 'contract', 'id_document', 'other')),
    CONSTRAINT chk_documents_status
        CHECK (status IN ('pending', 'processing', 'ready', 'failed', 'deleted')),
    CONSTRAINT chk_documents_storage_provider
        CHECK (storage_provider IN ('garage', 'azure_blob')),
    CONSTRAINT chk_documents_virus_scan_status
        CHECK (virus_scan_status IN ('pending', 'clean', 'infected'))
);

CREATE INDEX idx_documents_owner_user_id ON documents (owner_user_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_documents_status ON documents (status, virus_scan_status)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_documents_type ON documents (document_type)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_documents_virus_pending ON documents (created_at)
    WHERE virus_scan_status = 'pending' AND deleted_at IS NULL;
CREATE INDEX idx_documents_storage_key ON documents (storage_key)
    WHERE storage_key IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### document_thumbnails
Generated thumbnail variants for photos and videos. Created by the transcoding/image-processing worker after the source document reaches `ready` status.

```sql
CREATE TABLE document_thumbnails (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    document_id UUID NOT NULL REFERENCES documents (id),
    variant     VARCHAR(20) NOT NULL,       -- size/type variant
    storage_key TEXT NOT NULL,             -- Full object key in the same bucket as the parent document
    width_px    INT NOT NULL,
    height_px   INT NOT NULL,
    size_bytes  BIGINT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — thumbnails are replaced, not updated
    -- No deleted_at — deleted when parent document is deleted

    CONSTRAINT chk_document_thumbnails_variant
        CHECK (variant IN ('thumb_sm', 'thumb_md', 'thumb_lg', 'poster')),
    CONSTRAINT uq_document_thumbnails_variant UNIQUE (document_id, variant)
);

CREATE INDEX idx_document_thumbnails_document_id ON document_thumbnails (document_id);
```

---

### esignature_requests
Dropbox Sign (HelloSign) e-signature request tracking. One row per signature request sent. The `provider_signature_request_id` is the Dropbox Sign API's canonical identifier — it drives webhook matching.

```sql
CREATE TABLE esignature_requests (
    id                              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id                        UUID NOT NULL,               -- References DB 3 (Lease) leases.id
    requester_user_id               UUID NOT NULL,               -- References DB 1 (Identity) users.id
    provider                        VARCHAR(50) NOT NULL DEFAULT 'dropbox_sign',
    provider_signature_request_id   VARCHAR(255),
    status                          VARCHAR(30) NOT NULL DEFAULT 'pending',
    subject                         VARCHAR(255),
    message                         TEXT,
    signed_document_id              UUID REFERENCES documents (id),  -- Populated on completion
    requested_at                    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at                    TIMESTAMPTZ,
    expires_at                      TIMESTAMPTZ,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_esignature_requests_provider_id
        UNIQUE (provider_signature_request_id),
    CONSTRAINT chk_esignature_requests_status
        CHECK (status IN ('pending', 'out_for_signature', 'completed', 'declined', 'expired', 'error'))
);

CREATE INDEX idx_esignature_requests_lease_id ON esignature_requests (lease_id);
CREATE INDEX idx_esignature_requests_requester ON esignature_requests (requester_user_id);
CREATE INDEX idx_esignature_requests_status ON esignature_requests (status)
    WHERE status NOT IN ('completed', 'declined', 'expired');
CREATE INDEX idx_esignature_requests_provider_id ON esignature_requests (provider_signature_request_id)
    WHERE provider_signature_request_id IS NOT NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON esignature_requests
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### esignature_signers
Individual signer records within an e-signature request. Tracks per-signer status and timestamps for the audit trail.

```sql
CREATE TABLE esignature_signers (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    request_id  UUID NOT NULL REFERENCES esignature_requests (id),
    user_id     UUID NOT NULL,               -- References DB 1 (Identity) users.id
    email       VARCHAR(255) NOT NULL,
    name        VARCHAR(200) NOT NULL,
    order_num   SMALLINT NOT NULL DEFAULT 0, -- Signing order (0 = no order enforced)
    status      VARCHAR(20) NOT NULL DEFAULT 'pending',
    signed_at   TIMESTAMPTZ,
    declined_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No updated_at — status changes create an audit trail in DB 9
    -- No deleted_at — signer records are permanent once created

    CONSTRAINT chk_esignature_signers_status
        CHECK (status IN ('pending', 'viewed', 'signed', 'declined'))
);

CREATE INDEX idx_esignature_signers_request_id ON esignature_signers (request_id);
CREATE INDEX idx_esignature_signers_user_id ON esignature_signers (user_id);
CREATE INDEX idx_esignature_signers_status ON esignature_signers (request_id, status);
```

---

### qr_codes
Generated QR codes for field use — check-in, property access, and harvest reporting. Each QR code maps to a `token` that the mobile app and field tools resolve at scan time.

```sql
CREATE TABLE qr_codes (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    code_type       VARCHAR(20) NOT NULL,
    target_id       UUID NOT NULL,           -- The entity ID this QR resolves to
    target_type     VARCHAR(50) NOT NULL,    -- e.g. 'lease', 'property', 'check_in'
    token           VARCHAR(100) NOT NULL,   -- The short token encoded in the QR image
    document_id     UUID REFERENCES documents (id), -- The generated QR image file (NULL until generated)
    expires_at      TIMESTAMPTZ,
    last_scanned_at TIMESTAMPTZ,
    scan_count      INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ,

    CONSTRAINT uq_qr_codes_token UNIQUE (token),
    CONSTRAINT chk_qr_codes_type
        CHECK (code_type IN ('check_in', 'property_access', 'harvest_report'))
);

CREATE INDEX idx_qr_codes_token ON qr_codes (token)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_qr_codes_target ON qr_codes (target_type, target_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_qr_codes_expires_at ON qr_codes (expires_at)
    WHERE expires_at IS NOT NULL AND deleted_at IS NULL;

CREATE TRIGGER set_updated_at BEFORE UPDATE ON qr_codes
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### print_jobs
Generated PDF jobs (lease agreements, harvest reports, property maps, field guides). Queued by application services, processed by `GeneratePrintJob` job, and linked to a `documents` row when complete.

```sql
CREATE TABLE print_jobs (
    id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    job_type    VARCHAR(30) NOT NULL,
    owner_user_id UUID NOT NULL,             -- References DB 1 (Identity) users.id
    status      VARCHAR(20) NOT NULL DEFAULT 'queued',
    target_id   UUID NOT NULL,               -- The entity this PDF is generated for
    target_type VARCHAR(50) NOT NULL,        -- e.g. 'lease', 'harvest_log', 'property'
    document_id UUID REFERENCES documents (id), -- NULL until PDF is generated and stored
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- No deleted_at — completed jobs are retained for re-download

    CONSTRAINT chk_print_jobs_type
        CHECK (job_type IN ('lease_agreement', 'harvest_report', 'property_map', 'field_guide')),
    CONSTRAINT chk_print_jobs_status
        CHECK (status IN ('queued', 'processing', 'ready', 'failed'))
);

CREATE INDEX idx_print_jobs_owner ON print_jobs (owner_user_id, created_at DESC);
CREATE INDEX idx_print_jobs_status ON print_jobs (status)
    WHERE status IN ('queued', 'processing');
CREATE INDEX idx_print_jobs_target ON print_jobs (target_type, target_id);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON print_jobs
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

## Eloquent Models

```php
namespace App\Models\Documents;

class Document extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'documents';
    protected $table      = 'documents';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'is_public'        => 'boolean',
            'virus_scanned_at' => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
        ];
    }

    public function thumbnails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentThumbnail::class, 'document_id');
    }
}
```

```php
namespace App\Models\Documents;

class DocumentThumbnail extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'documents';
    protected $table      = 'document_thumbnails';
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
namespace App\Models\Documents;

class EsignatureRequest extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'documents';
    protected $table      = 'esignature_requests';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at'   => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function signers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EsignatureSigner::class, 'request_id');
    }

    public function signedDocument(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }
}
```

```php
namespace App\Models\Documents;

class EsignatureSigner extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'documents';
    protected $table      = 'esignature_signers';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'signed_at'   => 'datetime',
            'declined_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Documents;

class QrCode extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'documents';
    protected $table      = 'qr_codes';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'expires_at'      => 'datetime',
            'last_scanned_at' => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Documents;

class PrintJob extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'documents';
    protected $table      = 'print_jobs';
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

    public function document(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
```

---

## Service Layer

```
App\Services\Documents\
├── DocumentService.php         -- Upload, serve, delete, virus scan status
├── EsignatureService.php       -- Dropbox Sign API integration, webhook handling
├── QrCodeService.php           -- Generate, resolve, and invalidate QR codes
└── VideoService.php            -- Transcoding triggers, thumbnail generation
```

Cross-DB resolution example — looking up a lease for an e-signature request:

```php
// CORRECT — service-layer cross-DB assembly
public function getRequestWithLease(string $requestId): array
{
    $request = EsignatureRequest::findOrFail($requestId);

    // Cross-DB fetch — lease lives in DB 3
    $lease = app(\App\Services\Lease\LeaseService::class)
        ->getLeaseSummary($request->lease_id);

    return ['request' => $request, 'lease' => $lease];
}

// WRONG — do not do this
$request->lease; // Eloquent relationship across DB connections — will fail
```

---

## Queue Jobs

| Job | Queue | Trigger |
|---|---|---|
| `App\Jobs\Documents\ScanUploadedFile` | `default` | Document uploaded |
| `App\Jobs\Documents\GenerateThumbnails` | `default` | Document reaches `ready` status |
| `App\Jobs\Documents\GeneratePrintJob` | `default` | PrintJob created |
| `App\Jobs\Documents\GenerateQrCode` | `default` | QrCode created |
| `App\Jobs\Documents\ProcessEsignatureWebhook` | `priority` | Dropbox Sign webhook received |

---

## Common Pitfalls

- **Never hard-delete a `documents` row.** Set `status = 'deleted'` and soft-delete. The storage key must remain for audit purposes.
- **Never serve a file before `virus_scan_status = 'clean'`.** The `DocumentService::getSignedUrl()` method enforces this — do not bypass it.
- **`esignature_signers.email` may differ from the user's login email.** Always use the signer's email as stored — the user may have updated their login email after the signature request was created.
- **QR code tokens are short-lived for security.** Always check `expires_at` before resolving a token. `QrCodeService::resolve()` handles this automatically.
- **Cross-DB references (`lease_id`, `owner_user_id`) are not enforced by foreign key.** Always validate existence in the service layer before creating a document or e-signature request.
