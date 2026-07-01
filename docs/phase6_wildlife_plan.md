# Phase 6 — Wildlife & Field Operations — Build Plan

> Status: approved 2026-06-30. Source-of-truth schemas: `docs/data_model/db05_wildlife.md`
> (wildlife) + `docs/data_model/db13_geospatial.md` (GPS). This plan supersedes the stale
> checklist in `build_roadmap.md` Phase 6 and records the four scope decisions made at planning
> time. Build order at the bottom.

**Goal:** a hunter can log a harvest (GPS, quota enforcement, CWD compliance gate) and manage
sightings/trail cameras from the member portal **and the mobile app**, working **offline** with
conflict-safe sync.

## Scope decisions (locked 2026-06-30)

1. **Offline = built in 6.3** (not deferred). Workbox service worker + IndexedDB write queue +
   Background Sync. No PWA layer exists today — this is net-new infrastructure and the highest-risk
   item in the phase.
2. **Include fishing + trophies.** Beyond the canonical `db05` doc: add `fishing_harvest_logs` and a
   dedicated `trophies` (B&C / SCI) table. **`db05_wildlife.md` SSOT must be updated to match.**
3. **External integrations = feature-flagged mocks.** AI scoring (`ai_trophy_scoring`) and trail-cam
   vendor APIs (`trail_camera_integration`) are built behind flags with a mock implementation; live
   wiring deferred until endpoints/keys exist (same approach used for Stripe early on).
4. This plan is persisted here and then built.

---

## Cross-cutting security model (the spine)

**DB 5 has NO row-level security** — per `db05_wildlife.md`: *"field operation records are
access-controlled at the service layer, not DB RLS."* Consequence: **the service layer is the only
authorization boundary.** A missing standing check = silent cross-tenant data exposure with no
database backstop (unlike the billing tables, where RLS caught mistakes). Every rule below is
load-bearing.

- **Standing gate on every read and write.** Centralize in a `WildlifeAccess` guard that reuses the
  existing `CheckInService::activeLeaseForUserProperty` / `mayCheckIn` logic (lessee **or** approved
  hunter on the lease; `abort_unless(..., 403)`). No controller or job touches a wildlife row without
  it. Reads by a landowner are scoped to their own properties.
- **GPS is sensitive.** Precise harvest/stand/camera points reveal property boundaries and prime
  spots (trespass/poaching risk). The **public trophy gallery (`is_public=true`) never exposes precise
  coordinates** — animal + score only, location stripped/coarsened. Consistent with SEC-024 (member
  precise-GPS gating).
- **Photos are untrusted input.** Every uploaded harvest/camera photo runs through `ScanHarvestPhoto`
  (virus scan) and is not servable until `documents.status='ready'`. **Strip EXIF GPS on ingest** —
  the app's GPS field is authoritative; EXIF is an uncontrolled second copy of the location.
- **Quota integrity under concurrency + offline replay.** Atomic
  `UPDATE ... WHERE current_harvest < max_harvest RETURNING *`; 0 rows → reject (409). Idempotent on
  `local_record_id` so an offline replay cannot double-count the quota.
- **CWD is a legal compliance gate, audited.** Harvesting in a `positive` zone can legally require
  sample submission. Record the acknowledgment (`cwd_acknowledgments`) and audit via `AuditService`.
- **External calls are flagged + mockable.** AI scoring and vendor sync are outbound integrations —
  behind `ai_trophy_scoring` / `trail_camera_integration`, mock impl first, guard against SSRF/data
  leakage.
- **Non-negotiables:** no cross-DB SQL FKs; GPS lives only in DB 13 (harvest_logs stores a bare
  `location_geospatial_id` UUID); no Eloquent cross-connection relations; `AuditService` never throws;
  audit `harvest.logged`, `cwd.acknowledged`, and quota-exhaustion attempts.

---

## Schema reconciliation (build to the doc + agreed additions)

| Roadmap checklist | Canonical `db05` doc | Plan |
|---|---|---|
| `trail_camera_images` | `trail_camera_photos` | doc name |
| `species_seasons` | `seasons` | doc name |
| `cwd_acknowledgments` | absent (only `cwd_zones` metadata) | **ADD** (compliance record) |
| offline `local_record_id` | absent | **ADD** to `harvest_logs` + `wildlife_sightings` |
| `fishing_harvest_logs` | absent (decision 2) | **ADD** |
| `trophies` | folded into `harvest_logs` (decision 2) | **ADD** dedicated table |
| — | `population_surveys` (doc extra) | include |
| entitlement `full_harvest_log` | does not exist | harvest logging = **ungated core**; cameras gated by `trail_camera_integration` / `shared_trail_cams` |

**SSOT upkeep:** after 6.1, update `db05_wildlife.md` to add `cwd_acknowledgments`, `local_record_id`,
`fishing_harvest_logs`, and `trophies` so the doc and DB stay in sync.

---

## 6.1 — DB 5 migrations + DB 13 glue (foundation)

- Raw-SQL migrations (`unprepared()`), per doc: `harvest_logs`, `wildlife_sightings`, `trail_cameras`,
  `trail_camera_photos`, `harvest_quotas`, `seasons`, `cwd_zones`, `population_surveys`.
- Additions: `cwd_acknowledgments` (user_id, harvest_log_id, cwd_zone_id, acknowledged_at, audit ref);
  `fishing_harvest_logs`; `trophies` (scoring system, score, official/pending, links `harvest_log_id`);
  `local_record_id UUID NULL` + partial unique `(user_id, local_record_id) WHERE local_record_id IS
  NOT NULL` on harvest_logs + sightings.
- `GeospatialService::storeHarvestLocation(harvestLogId, lng, lat, accuracy)` → DB 13
  `harvest_locations`; harvest_logs keeps only the returned `location_geospatial_id`.
- Extend `wildlife/2026_06_16_000001_grant_runtime_permissions.php` for the new tables. **No RLS**, so
  audit the `ah_runtime` GRANTs deliberately (DML only, no owner path at runtime — SEC-043).
- **Verify:** `migrate:single wildlife --fresh` clean; `WildlifeSchemaTest` (UUID defaults, CHECKs, the
  two partial-unique indexes, `chk_harvest_quotas_not_exceeded`, cross-DB harvest-location write). Seed
  `seasons` + `cwd_zones` reference data.

## 6.2 — Wildlife services (logic + the security boundary)

- `WildlifeAccess` standing guard (delegates to `CheckInService`).
- `HarvestService::log()` — standing → atomic quota check → CWD zone lookup + ack requirement → GPS
  write (DB 13) → insert → dispatch `ScanHarvestPhoto` + AI-score jobs → `AuditService`. Idempotent on
  `local_record_id`.
- `QuotaService` — atomic increment/check + `remaining()`.
- `CwdService` — `GeospatialService::getCwdZonesForPoint` → required ack + regulations; records ack.
- `TrailCameraService` — registration + photo listing (gated `trail_camera_integration`).
- `FishingHarvestService`, `TrophyService` (decision 2).
- Models per doc + `FishingHarvestLog`, `Trophy`, `CwdAcknowledgment`, `Season`, `CwdZone`,
  `PopulationSurvey`.
- **Verify:** service tests incl. **negative authz** (non-lessee → 403), quota-exhaustion 409,
  CWD ack required, offline dedup (same `local_record_id` twice → one row, quota +1).

## 6.3 — Member portal (web) + FULL offline (decision 1)

- Pages: `/member/harvest` (+ `/new`), `/member/wildlife`, `/member/cameras`, `/member/quota`,
  fishing + trophy views.
- **Offline stack (net-new):** Workbox service worker (precache shell + reference data), IndexedDB
  write queue for harvest/sighting submissions, Background Sync API flush on reconnect, offline cache
  of CWD zones / quota / seasons for client-side warnings. **Authoritative re-check is server-side at
  sync** (client checks advisory); quota-filled-while-offline → 409 surfaced as a clear conflict UI.
  `local_record_id` (minted client-side) is the dedup key.

## 6.4 — Wildlife jobs (feature-flagged/mockable — decision 3)

- `ScanHarvestPhoto` (virus scan → ready), `TagTrailCameraImage` (mock AI, flag), `SyncTrailCameraFeed`
  (mock vendor client, flag), `CheckQuotaAlerts` (daily 75/90% landowner), `UpdateHarvestQuota`,
  `ScoreHarvestPhoto` (mock, flag `ai_trophy_scoring`).

---

## Mobile API — Wildlife (revives the deferred mobile Phase D)

Follows Phase A/B/C conventions (Sanctum PAT, scoped abilities, `/v1`, per-route throttle, **controller
re-enforces every gate**). Critical difference: **no RLS backstop** — the service standing check is the
entire security boundary on every call.

**New ability:** `hunter:harvest` (reads reuse `hunter:read`).

| Method | Route | Notes |
|---|---|---|
| GET | `/v1/harvests` | caller's own logs (standing-scoped) |
| GET | `/v1/harvests/{harvest}` | ownership/standing 404 |
| POST | `/v1/leases/{lease}/harvests` | standing → quota → CWD ack → GPS→DB13; **idempotent on `local_record_id`** |
| GET | `/v1/leases/{lease}/quota` | remaining per species (offline cache + UI) |
| GET | `/v1/cwd/zones?state=XX` | CWD reference data (offline cache + ack gate) |
| GET / POST | `/v1/leases/{lease}/sightings` | wildlife sightings |
| GET / POST | `/v1/leases/{lease}/fishing` | fishing harvests |
| GET | `/v1/properties/{id}/cameras`, `/v1/cameras/{id}/photos` | **gated `trail_camera_integration`** |
| POST | `/v1/harvests/{id}/photos` | multipart → DocumentService → scan job; not servable till `ready` |

**Offline sync contract:** client mints `local_record_id` per queued record; on reconnect POSTs with
it; server **upserts idempotently** (seen id → return existing, no quota re-increment); reference data
(quota/CWD/seasons) exposed via GET for offline enforcement/warnings, but **authoritative re-check is
server-side at sync** (409 on quota conflict, clear body). `api_access` entitlement gates the *public
developer API*; the first-party mobile app uses Sanctum abilities and is not subject to it.

---

## Build order

6.1 foundation (unblocks everything incl. mobile) → 6.2 services + `WildlifeAccess` guard → **mobile
API** (thin over 6.2, closes deferred Phase D) → 6.3 web UI + offline → 6.4 jobs. Each slice:
branch-per-feature, in-container tests green, Pint clean, commit + push, PR into `main`.
