# Harvest Log Photo Uploads — Mechanism, Data, and How It Compares to Every Other Upload Surface

_Last updated: 2026-07-02. Covers the pipeline shipped in PRs #51–#53 (harvest photos, edit, GPS map)._

---

## TL;DR

- **There is no dedicated web upload controller for harvest photos.** Photos ride the harvest form's own
  POST as multipart fields (`photos[]`), handled inside `WildlifeController::harvestStore` /
  `harvestUpdate`. The **mobile API** does have a dedicated endpoint
  (`POST /api/v1/harvests/{harvest}/photos` → `Api\HarvestController::storePhoto`). Both funnel into the
  **same service method**: `HarvestService::attachFieldPhoto()`.
- One photo produces **three records in three databases**: the file + metadata row in **DB 11
  `documents`**, an id appended to **DB 5 `harvest_logs.field_photos`** (JSONB), and a gallery mirror in
  **DB 1 `profile_photos`** — assembled at the service layer, no cross-DB SQL.
- Every photo is **re-encoded through GD** (strips ALL metadata, critically EXIF GPS — SEC-024) unless the
  hunter explicitly opts to **keep location data**, in which case the original bytes are stored but the
  gallery row is flagged `is_location_private` so it can never be served publicly (**SEC-061**).
- Every photo is **virus-scanned** (`ScanDocumentForViruses`, ClamAV) before it is servable; nothing
  renders until `documents.status = 'ready'`.
- **Platform-wide**, all uploads converge on `DocumentService` and DB 11 — but there are four real
  divergences worth knowing about (storage-path layout, EXIF handling on the profile gallery, hardcoded
  disks on serve routes, avatar overwrite). See [§8](#8-consistency-assessment) — that section is the
  answer to "are we actually using the strategy?"

---

## 1. Technology stack

| Layer | Technology |
|---|---|
| Browser form | Inertia.js `useForm` — when a `File` is present in the form data it automatically posts `multipart/form-data` (no FilePond, no XHR hand-rolling on this form) |
| Transport | Plain Laravel POST route (`/member/harvest`, throttle 30/min); edit posts `_method=PUT` because PHP cannot parse multipart bodies on a real PUT |
| Validation | Laravel validator: `photos` array max 6, each `image`, `mimes:jpg,jpeg,png,webp`, `max:8192` KB (mobile API: single `photo`, max 15 MB) |
| Image processing | **GD** (`imagecreatefromstring` → `imagejpeg/png/webp`) — full decode + re-encode |
| File storage | Laravel `Storage` on the disk from `config('filesystems.defaults.documents')` / `DOCUMENTS_DISK` env — `local` in dev, Garage (S3-compatible) on-prem, Azure Blob later (see `docs/storage_strategy.md`) |
| Metadata | DB 11 `documents` via `DocumentService` |
| Virus scan | `ScanDocumentForViruses` queue job (ClamAV), dispatched at registration, fail-closed |
| Gallery mirror | DB 1 `profile_photos` via `ProfilePhotoService::createForHarvestPhoto` |

---

## 2. End-to-end flow (web form)

`resources/js/Pages/Member/Harvest/New.tsx` → `POST /member/harvest`

1. **Client** — the member picks up to 6 photos (`accept="image/jpeg,image/png,image/webp"`, object-URL
   thumbnails, per-photo remove). An optional **"Keep location data on these photos"** checkbox
   (`keep_photo_location`, default OFF) applies to the batch. On submit, Inertia detects the `File[]` and
   posts multipart. **Offline saves drop photos** — files cannot sit in the IndexedDB write queue, and the
   form says so.
2. **Controller** — `WildlifeController::harvestStore` validates (see stack table), calls
   `HarvestService::log(...)` to create the harvest first (standing → dedup → CWD gate → atomic quota
   claim → GPS to DB 13 → insert). Photos are attached **only when `wasRecentlyCreated`** — an offline
   dedup replay of the same `local_record_id` never re-attaches duplicates.
3. **Service** — for each file, `HarvestService::attachFieldPhoto($userId, $harvestId, $file, $keepLocation)`:
   1. **Re-asserts authorization** via `findForUser` (owner / standing / manager; strangers 404 — DB 5 has
      no RLS, so this is the entire fence).
   2. **Sanitizes the bytes** —
      - `keepLocation = false` (default): `sanitizeImage()` decodes through GD and re-encodes in the same
        format. This drops **every** byte of embedded metadata — EXIF GPS, serial numbers, thumbnails —
        and destroys polyglot payloads (a file that is both a valid image and valid HTML/JS does not
        survive re-encoding). **SEC-024.**
      - `keepLocation = true`: `validateOriginalImage()` still fully decodes through GD (rejects corrupt/
        polyglot bytes) and whitelists JPEG/PNG/WebP, but stores the **original bytes** so the EXIF GPS the
        hunter wants to keep survives.
   3. **Stores + registers** — `DocumentService::storeRawBytes($bytes, $userId, 'photo', $filename, $mime)`
      writes to `documents/{userId}/{uuid}.{ext}` on the documents disk and creates the DB 11 row in
      `status='processing'`, `virus_scan_status='pending'`, with a SHA-256 checksum, then dispatches
      `ScanDocumentForViruses`. (`document_type` **must** be `'photo'` — the `chk_documents_type` CHECK
      allows only photo/video/pdf/contract/id_document/other.)
   4. **Links to the harvest** — the document id is appended to `harvest_logs.field_photos` (JSONB array,
      DB 5). That column stores **ids only**, never bytes or paths.
   5. **Mirrors to the profile gallery** — `ProfilePhotoService::createForHarvestPhoto` creates the DB 1
      `profile_photos` row: auto-caption ("Whitetail Deer harvest"), species auto-tag mapped into the
      controlled `PhotoTagVocabulary` (`whitetail_deer→whitetail`, `bear→black_bear`,
      `rabbit/squirrel→small_game`; `antelope`/`other` get no tag), next `sort_order`, and — when keeping
      location — EXIF GPS parsed into `exif_latitude/exif_longitude` **plus `is_location_private = TRUE`**
      (SEC-061). The mirror is `rescue()`-wrapped: a gallery failure never voids the harvest attachment.
   6. **Audits** — `harvest.photo_attached` (and the mirror audits `profile_photo.uploaded`) via
      `AuditService`.
4. **Async** — the ClamAV job either `markReady()` (`status='ready'`, `virus_scan_status='clean'`) or
   `markQuarantined()` (`'quarantined'`/`'infected'`). **Nothing is servable until ready**: the harvest
   index/detail thumbnails and the GPS-map popups only emit URLs for `status='ready'` documents, and both
   serve routes 404 quarantined files even for the owner.

### Edit flow

`PUT /member/harvest/{harvest}` (`harvestUpdate`) accepts the same `photos[]`/`keep_photo_location`
**plus** `remove_photo_ids[]`. Removal goes through `HarvestService::removeFieldPhoto` (owner-only):
detaches the id from `field_photos` and soft-deletes **both** the DB 11 document and its DB 1 gallery
mirror via `ProfilePhotoService::delete` (owner-scoped).

### Mobile API flow

`POST /api/v1/harvests/{harvest}/photos` (`Api\HarvestController::storePhoto`, Sanctum
`abilities:hunter:harvest`, throttle 30/min) — single `photo` + optional `keep_location` boolean. Calls
the **identical** `attachFieldPhoto`, so the sanitize/scan/mirror behavior is byte-for-byte the same as
the web. Returns `{document: {id, status: 'processing'}}` — the client polls/refreshes for readiness.

### Why no dedicated web upload controller?

Deliberate. On the web the photos are **part of the harvest submission** — one request creates the
harvest and attaches its photos, so a validation failure (bad species, full quota, CWD ack) never leaves
orphaned uploads, and the offline queue has a single JSON payload shape with photos cleanly excluded. The
mobile API needs the separate endpoint because its offline client creates the harvest first (idempotent
on `local_record_id`) and uploads photos opportunistically when signal allows.

---

## 3. What data is captured, and where

One uploaded photo produces:

**DB 11 `documents` (the file's system of record)**

| Field | Value |
|---|---|
| `owner_user_id` | the uploading hunter (bare UUID → DB 1; no cross-DB FK) |
| `document_type` | `photo` |
| `status` | `processing` → `ready` \| `quarantined` |
| `original_filename` | client filename, re-extensioned to match the real decoded format |
| `mime_type` | from the **decoded image type**, not the client's claim |
| `size_bytes`, `checksum_sha256` | of the stored (sanitized or validated-original) bytes |
| `storage_bucket` / `storage_key` / `storage_provider` | `documents/{userId}/{uuid}.{ext}` on the documents disk (`garage` \| `azure_blob`) |
| `virus_scan_status`, `virus_scanned_at` | scan lifecycle |
| `is_public` | always `false` |

**DB 5 `harvest_logs.field_photos`** — JSONB array of document UUIDs. Nothing else.

**DB 1 `profile_photos` (gallery mirror)**

| Field | Value |
|---|---|
| `user_id`, `document_id` | owner + the DB 11 file |
| `caption` | auto: "`<Species>` harvest" |
| `tags` | species auto-tag from the controlled vocabulary |
| `exif_latitude` / `exif_longitude` | parsed from EXIF **only when keep-location**; owner-facing only, never logged |
| `is_location_private` | **TRUE whenever the stored file retains location metadata** (SEC-061) |
| `sort_order` | appended to the gallery |

**What is deliberately NOT captured:** GPS coordinates never land in DB 5 or DB 11. The harvest's own
location (from the form's Capture GPS, unrelated to the photo) lives **only in DB 13
`harvest_locations`**; the photo's EXIF GPS lives only inside the kept file + the owner-facing `exif_*`
columns.

---

## 4. Serving paths (two routes, different audiences)

| Route | Audience | Gates |
|---|---|---|
| `GET /member/profile/photos/{documentId}` (`ProfileController::servePhoto`) | **Owner only** | session user must equal `owner_user_id` (403); quarantined → 404; `nosniff`; private cache |
| `GET /member/harvest-photos/{document}` (`WildlifeController::harvestPhoto`) | **Co-hunters** (the GPS-map popup) | document must be `ready`; must actually be in some harvest's `field_photos`; non-owner additionally needs past/present standing on the property (or manages it), the harvest must **not** hide its spot, and the file must **not** be location-retaining (`is_location_private` → 404). All failures are non-disclosing 404s. |

Public serving does not exist yet. When Phase 7+ public galleries land, they are **bound** by SEC-061:
exclude every `is_location_private` row — and see the gap flagged in §8.2.

---

## 5. Security controls, in one place

- **SEC-024** — precise on-property GPS is member-only. Default EXIF strip; harvest GPS only in DB 13;
  map/photos gated on standing.
- **SEC-061** — the keep-location opt-in can never leak publicly: flag set at write time, enforced at
  every non-owner serve, recorded in `security.md` with the Phase 7 binding constraint.
- **Untrusted-input handling** — full GD decode (rejects polyglots/corrupt files), format whitelist,
  server-derived mime/extension, size + count caps, per-route throttles, uuid-constrained routes.
- **Virus scan fail-closed** — registered as `processing`, servable only after `clean`; quarantined files
  404 even for their owner; scanner errors retry, never mark ready.
- **Authorization** — DB 5 has no RLS; `WildlifeAccess`/`findForUser` standing checks inside the service
  are the entire fence and are re-asserted on every attach/remove/serve.
- **Idempotency** — offline replays (`local_record_id`) never duplicate photo attachments.
- **Audit** — every attach/remove/mirror writes through `AuditService` (which never throws).

---

## 6. The platform-wide upload survey

Every upload surface in the codebase, and how it enters storage:

| Surface | Entry point | Path into DB 11 | Sanitization | Scan | Notes |
|---|---|---|---|---|---|
| **Harvest field photos** (web + API) | `WildlifeController::harvestStore/Update`, `Api\HarvestController::storePhoto` | `storeRawBytes` (type `photo`) | **GD re-encode** (or GD-validated original on opt-in) | ✅ | This document |
| **Profile gallery** | `ProfileController::uploadPhoto` (FilePond) | hand-rolled `putFileAs('profile_photos/{uid}')` + `register` (type `profile_photo`) | **none** — original bytes kept, EXIF GPS read into columns | ✅ | ⚠️ see §8.2 |
| **Avatars** (web + mobile) | `ProfileController::uploadAvatar`, `Api\ProfileController` | hand-rolled `putFileAs('avatars/{uid}.{ext}')` + `register` | none | ✅ | fixed key = in-place overwrite; cache-busted by doc id |
| **Incident / dispute / damage-claim evidence** | `MemberController` → `IncidentService`/`DisputeService`/`DamageClaimService` | `storeUploadedFile(..., 'photo', unattached: true)` → `attachDocuments()` after the owning transaction commits | none | ✅ (on attach) | the **unattached pattern** — no orphaned scans if the transaction rolls back; reaper + `deleteUnattachedByIds` clean up failures |
| **Application DL / hunting-license photos** | `ApplyController` → `ApplicationService` | `storeUploadedFile(..., 'driver_license'/'hunting_license', unattached: true)` | none | ✅ | PII-class docs; admin serving is audited (SEC-050) |
| **Ownership proof** | `PropertyOwnershipController` | `storeUploadedFile(..., 'ownership_proof', unattached: true)` | none | ✅ | |
| **Signup ID document** | `AuthController::register` | `storeUploadedFile(..., 'id_document')` | none | ✅ | |
| **Lease documents** (member uploads) | `LeaseDocumentService` | `storeUploadedFile` | none | ✅ | tagged (insurance, rules…) |
| **Property photos** (landowner/admin) | `PropertyPhotoController` / `PropertyService` | `storeUploadedFile` | none | ✅ | |
| **Property map images** | `PropertyMapController` → `PropertyMapService` | temp `local` dir → `storeUploadedFile`; optional `import_exif` reads GPS via `App\Support\ExifGps` | none | ✅ | temp files reaped by age |
| **E-sign executed PDFs / lease agreement PDFs** | `ProcessDropboxSignWebhook`, `LeaseAgreementPdfService` | `storeRawBytes` (server-generated/fetched bytes) | n/a (server-origin) | ✅ | |

**The pattern that IS consistent everywhere:** every file ends as a DB 11 `documents` row created by
`DocumentService` (`register` / `storeUploadedFile` / `storeRawBytes`), every user-supplied file is
virus-scanned, everything is status-gated before serving, and transactional flows use the
`unattached → attachDocuments` promotion so a rolled-back parent never leaves live documents.

---

## 7. The three `DocumentService` entry points (when to use which)

| Method | Use when | Behavior |
|---|---|---|
| `storeUploadedFile(UploadedFile, owner, type, unattached?)` | You have a browser upload and no byte-level processing to do | Writes `documents/{uid}/{uuid}.{ext}` on the documents disk, checksums, registers, scans (or holds `unattached`) |
| `storeRawBytes(bytes, owner, type, filename, mime)` | You transformed the bytes first (harvest GD sanitize) or generated/fetched them server-side (PDFs) | Same layout + registration from a byte string |
| `register(...)` | The file is **already** in storage under a custom layout | Metadata row + scan only — this is the "escape hatch" the profile/avatar paths use |

**Rule of thumb going forward: new upload features should use `storeUploadedFile`/`storeRawBytes`, never
raw `putFileAs` + `register`.** The escape hatch is why the divergences in §8 exist.

---

## 8. Consistency assessment

The strategy (one `DocumentService`, one `documents` table, universal scanning, status gating) **is being
followed everywhere at the metadata/scan layer.** The drift is at the file-handling edges:

### 8.1 Two storage layouts, one of them hand-rolled
`storeUploadedFile`/`storeRawBytes` write `documents/{uid}/…` on the **configurable** documents disk.
The profile gallery and avatars bypass that and hand-write `profile_photos/{uid}/…` and `avatars/{uid}.…`
on a **hardcoded `local` disk** — as do their serve routes (`servePhoto`, `serveAvatar`, and the harvest
photo route, which reads `disk('local')`). In dev these coincide because `DOCUMENTS_DISK` defaults to
`local`; **the day `DOCUMENTS_DISK` moves to Garage/Azure, the serve routes keep reading `local` and the
profile paths keep writing there** — a silent split. Fix direction: serve from
`config('filesystems.defaults.documents')` and migrate the two hand-rolled writers onto
`storeUploadedFile`.

### 8.2 EXIF handling is inconsistent — and it matters for Phase 7 ⚠️
Harvest photos strip EXIF by default and **flag** the exception (`is_location_private`). The **direct
profile-gallery upload does neither**: it stores the original bytes (EXIF GPS intact) and leaves
`is_location_private = FALSE`. Harmless today (serve is owner-only), but the SEC-061 Phase 7 rule
("exclude flagged rows from public serving") **would not protect these files** — a hunter whose gallery
goes public would publish photos whose EXIF still contains coordinates. Before any public gallery ships,
either (a) strip EXIF on the direct-upload path too (mirroring the harvest default, with the same opt-in),
or (b) set `is_location_private = TRUE` whenever EXIF GPS is detected on upload. Option (a) is the
consistent one.

### 8.3 Only harvest photos defend against polyglot files
The GD re-encode/decode-validate exists only on the harvest path. Everything else stores client bytes
verbatim (mitigated by ClamAV + `nosniff` + correct Content-Type, so this is defense-in-depth, not a
hole). If we want one image-ingest standard, `sanitizeImage`/`validateOriginalImage` belong in a shared
support class that the profile/property/incident photo paths call too.

### 8.4 Avatars overwrite in place
Fixed storage key per user means no history and a re-used URL (already cache-busted by doc id). Fine as a
product choice; noted here only because it's another `register`-escape-hatch user.

**Suggested follow-ups (not yet done):** ① serve routes read the configured documents disk; ② profile
gallery upload strips EXIF by default with the same keep-location opt-in + flag; ③ extract the GD
sanitize into `App\Support\ImageSanitizer` and adopt it on the other image paths; ④ migrate the
profile/avatar writers onto `storeUploadedFile`. ② should land before any public-gallery work.

---

## 9. File / class reference

| Concern | Where |
|---|---|
| Web form | `resources/js/Pages/Member/Harvest/New.tsx` |
| Web controller (photos ride the harvest post) | `app/Http/Controllers/Member/WildlifeController.php` — `harvestStore`, `harvestUpdate`, `harvestPhoto`, `readyPhotoIds` |
| Mobile endpoint | `app/Http/Controllers/Api/HarvestController.php` — `storePhoto` |
| Attach / sanitize / remove | `app/Services/Wildlife/HarvestService.php` — `attachFieldPhoto`, `sanitizeImage`, `validateOriginalImage`, `removeFieldPhoto` |
| Gallery mirror | `app/Services/Identity/ProfilePhotoService.php` — `createForHarvestPhoto` |
| File system of record | `app/Services/Documents/DocumentService.php`; `app/Jobs/Documents/ScanDocumentForViruses.php` |
| Owner serve | `app/Http/Controllers/Member/ProfileController.php` — `servePhoto` |
| Map popup photos on the lease page | `app/Services/Wildlife/HarvestMapService.php` — `harvestPhotoUrls` |
| Security findings | `security.md` — SEC-024, SEC-050, SEC-061 |
| Storage infrastructure | `docs/storage_strategy.md` |
| Tests | `tests/Feature/Member/HarvestPhotoWebTest.php`, `tests/Feature/Api/HarvestPhotoTest.php`, `tests/Feature/Member/HarvestMapTest.php` (photo-route gates) |
