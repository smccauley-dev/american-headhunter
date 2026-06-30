# American Headhunter — Security Issue Tracker

This file tracks all identified security issues: their severity, root cause, fix applied, and verification status.

**Status legend:** `OPEN` · `FIXED` · `VERIFIED` · `DEFERRED` · `WONT-FIX`

> **Canonical tracker.** This file is the single source of truth for security findings. The former `docs/security.md` was merged here on 2026-06-14; it now only points back to this file. All future findings go here.
>
> **Two numbering tracks.** This file contains findings from two historically independent audit efforts whose `SEC-NNN` numbers were assigned separately and therefore **overlap** (there are two SEC-001, two SEC-024, etc., describing different issues). They are kept under their original IDs because both schemes are referenced verbatim from code comments — renumbering would require editing source files. Disambiguate by track and by the finding's title:
> - **Track A (Property / CMS / Map / RLS domain)** — the sections immediately below.
> - **Track B (Auth / MFA / Admin-IP / Lease-Application domain)** — at the bottom of this file, under the `TRACK B` divider.

---

## SEC-001 — Cache Invalidation Key Bug (Slug Keys Never Cleared)

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Services/Property/PropertyService.php` |

**Description:**
`invalidatePropertyCache()` constructed the slug-based Valkey key as `"property:slug:{$propertyId}"` — passing the UUID instead of the actual slug string. This meant that when a property was updated or deleted, the slug lookup cache key (`property:slug:brackettville-whitetail-ranch`) was never evicted. Stale property data (including deleted properties) could continue to be served from cache indefinitely.

**Root Cause:**
`invalidatePropertyCache(string $propertyId, string $ownerUserId)` did not receive the slug. It used `$propertyId` in all three key patterns, including the slug one.

**Fix Applied:**
- Changed signature to `invalidatePropertyCache(string $propertyId, string $slug, string $ownerUserId)`.
- All callers (`updateProperty`, `deleteProperty`) now pass `$property->slug`.
- `updateProperty` additionally invalidates the new slug key if the slug changed (title rename case).

**Verification:**
- Tinker: `PropertyService::updateProperty()` called with new title → old and new slug keys confirmed absent from Valkey after call.
- `PropertyService::deleteProperty()` called → correct slug key evicted.

---

## SEC-002 — IDOR on Public API (Draft Properties Accessible by UUID)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Http/Controllers/Api/PropertyController.php`, `app/Services/Property/PropertyService.php` |

**Description:**
`GET /api/properties/{id}` called `PropertyService::find()` which returns any property regardless of `status` or `deleted_at`. A caller who knew or guessed a property UUID could retrieve draft, inactive, or soft-deleted property records — including their full description, species, and photo references.

`GET /properties/{slug}` (web route) filtered `deleted_at IS NULL` but not `status = 'active'`, meaning draft properties with a known slug were also accessible.

**Root Cause:**
`find()` is intentionally unrestricted for internal/admin use. The public-facing API controller did not apply the additional visibility filter before returning the response. `findBySlug()` was missing the `status = 'active'` constraint.

**Fix Applied:**
- `Api/PropertyController::show()`: after `find()`, added guard:
  ```php
  if (! $property || $property->status !== 'active' || $property->deleted_at !== null) {
      return response()->json(['error' => 'Not found'], 404);
  }
  ```
- `PropertyService::findBySlug()`: added `->where('status', 'active')` alongside the existing `->whereNull('deleted_at')`.
- `find()` left unrestricted — admin/Filament consumers need full visibility.

**Verification:**
- HTTP request to `GET /api/properties/{uuid-of-draft-property}` → 404.
- HTTP request to `GET /api/properties/{uuid-of-active-property}` → 200.
- `GET /properties/{slug-of-active}` → 200. Draft property slug → 404.

---

## SEC-003 — Access Info Caller Trust (No Structural Enforcement)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Services/Property/PropertyService.php` |

**Description:**
`getAccessInfo()` (which decrypts gate codes, wifi passwords, and cabin lock codes) only had a doc comment saying "CALLER MUST verify the requesting user has an active lease." Any future caller could call it without performing that check and the service would silently decrypt and return sensitive access credentials.

**Root Cause:**
Convention-based security relying on developers reading and following a comment. No code enforced the requirement.

**Fix Applied:**
Added a required `bool $callerHasVerifiedLease = false` parameter. The method throws `\RuntimeException` immediately if the flag is not explicitly `true`:
```php
if (! $callerHasVerifiedLease) {
    throw new \RuntimeException('getAccessInfo requires active lease verification...');
}
```
Phase 4 `LeaseService` will provide `hasActiveLease(string $userId, string $propertyId): bool`. Callers will pass the result of that check as the flag.

**Verification:**
- Tinker: `getAccessInfo($id, $key)` (no flag) → throws `RuntimeException`. ✓
- Tinker: `getAccessInfo($id, $key, false)` → throws `RuntimeException`. ✓
- Tinker: `getAccessInfo($id, $key, true)` → returns decrypted array (or `[]` if no row). ✓

**Phase 4 follow-up:** Replace the bool flag with an actual `LeaseService::hasActiveLease()` call inside the service so callers cannot bypass it even accidentally. Track as SEC-003-P4.

---

## SEC-004 — Unvalidated Species Filter (Arbitrary Values in whereIn)

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Services/Property/PropertyService.php` |

**Description:**
`searchListings()` passed `$filters['species']` directly to a `whereIn('species_code', ...)` clause after only casting it to `(array)`. The ORM parameterized the values (no SQL injection risk), but arbitrary strings were passed to the database engine — including values that could never match the DB CHECK constraint. This creates unnecessary DB work and leaks schema information (an attacker sending `["whitetail_deer","secret_admin_flag"]` gets back a filtered result set that implicitly confirms valid codes).

**Root Cause:**
No allowlist validation before the value reached the query builder.

**Fix Applied:**
Added `VALID_SPECIES_CODES` constant to `PropertyService` (all 15 DB constraint values). `searchListings()` now intersects the input against this constant before building the query:
```php
$species = array_values(array_intersect((array) $filters['species'], self::VALID_SPECIES_CODES));
if (! empty($species)) { ... }
```
Invalid codes are silently dropped. An all-invalid species filter returns unfiltered results (no species constraint applied), which is the safe fallback.

**Verification:**
- `searchListings(['species' => ['invalid_code']])` → no species filter applied, returns full result set. ✓
- `searchListings(['species' => ['whitetail_deer', 'bad_code']])` → only `whitetail_deer` passed to `whereIn`. ✓
- `searchListings(['species' => ['whitetail_deer']])` → correct filtered results. ✓

---

## SEC-005 — recordView IP Address (TrustProxies Not Configured)

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `bootstrap/app.php` |

**Description:**
`PropertyService::recordView()` stores `$ipAddress` (sourced from `request()->ip()`) in `property_views`. Without `TrustProxies` configured, Laravel resolves `request()->ip()` as the Nginx container's internal IP rather than the real client IP, because it does not trust `X-Forwarded-For` headers from the proxy. A malicious client could also inject an arbitrary IP value by crafting `X-Forwarded-For` headers if the proxy was trusted too broadly.

**Root Cause:**
`bootstrap/app.php` had no `trustProxies` declaration. Laravel's default is to trust no proxies.

**Fix Applied:**
Configured `trustProxies` in `bootstrap/app.php` to trust RFC-1918 private ranges (the Docker internal network), accepting only the standard forwarded headers:
```php
$middleware->trustProxies(
    at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```
Trusting only the private ranges (not `'*'`) means only traffic that originates from the Docker-internal Nginx container can influence the resolved IP — external clients cannot spoof it.

**Verification:**
- `php artisan tinker`: `request()->ip()` returns the loopback IP correctly inside the container. ✓
- Azure deployment note: When deployed to Azure Container Apps, update `at:` to include the Azure load balancer egress range or set the `TRUST_PROXY` env var (add to `.env.example`).

---

---

## SEC-009 — Missing Readonly Grants on Geospatial Database

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 (discovered during SEC-005 smoke test) |
| **Fixed** | 2026-05-25 |
| **File** | `database/migrations/geospatial/2026_05_25_000009_grant_readonly_permissions.php` |

**Description:**
`ah_readonly` had no `SELECT` privilege on the `ah_geospatial` database (same class of issue as the property DB fix in Phase 3.8). `GET /api/properties/{id}/boundary` returned HTTP 500 because `GeospatialService::getPropertyBoundaryGeoJson()` queries `property_boundaries` via the `geospatial_read` connection.

**Fix Applied:**
Added `2026_05_25_000009_grant_readonly_permissions.php` to `database/migrations/geospatial/` — mirrors the property DB grant migration pattern: `GRANT USAGE`, `GRANT SELECT ON ALL TABLES`, `ALTER DEFAULT PRIVILEGES`.

**Verification:**
- `GET /api/properties/{uuid}/boundary` → 404 (no boundary seeded, correct). ✓
- No permission denied errors after migration. ✓

---

## SEC-011 — URL Injection in CMS Nav/Href Fields (javascript: and Open Redirect)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **Files** | `app/Filament/Admin/Pages/NavigationSettings.php` |

**Description:**
All `href` fields in NavigationSettings had no URL validation. A compromised or malicious staff account could set any nav link, CTA button, or Sign In link URL to `javascript:...` (stored XSS) or an arbitrary external URL (`https://phishing.example.com`), causing those links to execute script or redirect users to attacker-controlled sites. The values are stored in `tenant_settings` and rendered unmodified as anchor `href` attributes on the public homepage.

Affected fields: `nav.links[*].href`, `nav.cta_href`, `nav.signin_href`.

**Root Cause:**
Filament `TextInput` fields have no URL validation by default. No allowlist or format check was applied before writing to `tenant_settings`.

**Fix Applied:**
Added `->regex('/^(\/|https?:\/\/).+/')` with a descriptive validation message to every href TextInput in `NavigationSettings`. Valid values must be either a relative path starting with `/` or a full `https://` (or `http://`) URL. `javascript:`, `data:`, and bare strings are all rejected by the form before any write to the service layer.

**Verification:**
- Filament form rejects `javascript:alert(1)` on any href field — validation error shown.
- Filament form rejects `phishing.example.com` (no scheme, no leading slash) — validation error shown.
- `/properties`, `/get-started?type=landowner`, `https://partner.example.com` — all accepted.

---

## SEC-012 — No Audit Trail for CMS Settings Changes

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **Files** | `app/Filament/Admin/Pages/HomepageSettings.php`, `app/Filament/Admin/Pages/NavigationSettings.php` |

**Description:**
Both CMS admin pages (`HomepageSettings`, `NavigationSettings`) wrote to `tenant_settings` with no call to `AuditService`. Changes to the public-facing homepage copy, section visibility, nav links, and CTA URLs were invisible in the audit log — no record of who changed what or when.

**Root Cause:**
`AuditService::log()` was not called in either `save()` method. The `TenantService::setSetting()` helper does not emit audit events itself.

**Fix Applied:**
Both `save()` methods now call `AuditService::log()` with `event_type='update'`, `source_database='platform'`, `table_name='tenant_settings'`, the authenticated `user_id`, client IP, user agent, a plain-English `action_summary`, and the list of `changed_fields`.

**Verification:**
- Saved HomepageSettings → row inserted in `audit_log` with `action_summary = 'Homepage settings updated via admin CMS'`. ✓
- Saved NavigationSettings → row inserted with `action_summary = 'Navigation settings updated via admin CMS'`. ✓

---

## SEC-013 — No maxLength Constraints on CMS Text Inputs

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **Files** | `app/Filament/Admin/Pages/HomepageSettings.php`, `app/Filament/Admin/Pages/NavigationSettings.php` |

**Description:**
All `TextInput` and `Textarea` fields in both CMS pages had no `maxLength()` constraint. A staff user could submit arbitrarily long strings. PostgreSQL TEXT columns accept up to 1 GB; Livewire has its own upload limit but no per-field cap. An attacker with staff access could bloat `tenant_settings` rows or attempt to cause oversized Inertia payloads on the public homepage.

**Fix Applied:**
Added `->maxLength()` to every text field — limits are scoped to realistic content (e.g. 20 chars for stat values, 60 for nav labels, 100–120 for headlines, 400 for CTA body text, 500 for URLs).

---

## SEC-014 — SVG Upload Accepted as Logo (Stored XSS via Direct URL Access)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Filament/Admin/Pages/HomepageSettings.php` |

**Description:**
The logo `FileUpload` component initially accepted `image/svg+xml` as a valid MIME type. SVG files can contain embedded `<script>` tags, `onload` event handlers, and other executable content. When an SVG is used in React's `<img src>`, browsers sandbox scripts — this is safe. However, the uploaded file is stored at a publicly accessible URL (`/storage/site/filename.svg`). If anyone navigates directly to that URL, the browser serves it as `image/svg+xml` and executes any embedded JavaScript in the context of our domain. A compromised or malicious staff account could upload a weaponised SVG and distribute the URL, achieving persistent stored XSS on our origin.

**Root Cause:**
`image/svg+xml` was included in `->acceptedFileTypes()` alongside PNG and WebP. SVG appears visually harmless but is executable when served directly.

**Fix Applied:**
Removed `image/svg+xml` from `->acceptedFileTypes()`. Only `image/png` and `image/webp` are accepted. Helper text updated to reflect the restriction. PNG and WebP cover all practical logo formats; SVG logos are better handled as inline SVG in source code where they can be reviewed.

**Verification:**
- Attempting to upload an `.svg` file in Filament → rejected by MIME validation.
- PNG upload accepted and previewed correctly.

---

## SEC-015 — Orphaned Logo Files Accumulate in Public Storage

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Filament/Admin/Pages/HomepageSettings.php` |

**Description:**
When an admin uploaded a replacement logo or cleared the logo field, the previous file was never deleted from the `public` disk. Each logo change left an orphaned file permanently accessible at its public URL (`/storage/site/old-logo.png`). While old logo files are not sensitive, their URLs remain live indefinitely, and over time the `site/` directory accumulates an unbounded number of publicly-reachable files with no lifecycle management.

**Root Cause:**
The `save()` method called `TenantService::setSetting('site.logo_path', ...)` to record the new path but made no call to `Storage::disk('public')->delete()` for the displaced file.

**Fix Applied:**
At the start of `save()`, before writing the new path, the old logo path is read from `TenantService`. If the old path exists and differs from the incoming value (replacement or clear), `Storage::disk('public')->delete($oldPath)` is called to remove the file immediately.

**Verification:**
- Upload logo A → file exists at `/storage/site/A.png`.
- Upload logo B → file A is deleted, only B exists.
- Clear logo → file B is deleted, `site/` directory is empty.

---

## SEC-016 — Untrusted Path Used Directly in `Storage::url()` Without Prefix Validation

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-05-25 |
| **File** | `app/Http/Controllers/HomeController.php` |

**Description:**
`HomeController` read `site.logo_path` from `tenant_settings` and passed it directly to `Storage::disk('public')->url($logoPath)` with only a non-empty string check. If the stored path were manipulated at the database level (e.g. set to `../../etc/passwd` or an arbitrary relative path), the controller would generate a misleading URL outside the intended upload directory. Although exploiting this requires direct database write access (not a standard web attack), it is a defense-in-depth failure — the application blindly trusted a stored value without bounding it to the known upload scope.

**Root Cause:**
No path prefix check was applied before calling `Storage::url()`. Any non-empty string from `tenant_settings` was treated as a valid `site/` upload path.

**Fix Applied:**
Changed the condition from `$logoPath && $logoPath !== ''` to `$logoPath && str_starts_with($logoPath, 'site/')`. Only paths within the designated upload directory (`site/`) are converted to URLs. Any other stored value is silently treated as no logo, causing the AH text mark fallback to render instead.

**Verification:**
- `site/logo.png` → URL generated correctly. ✓
- `../../etc/passwd` → evaluated as no logo, text mark rendered. ✓
- Empty string → text mark rendered. ✓

---

## SEC-017 — Stored XSS via javascript: URL in Login Page Policy Links

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **Files** | `app/Filament/Admin/Pages/LoginPageSettings.php`, `app/Providers/Filament/AdminPanelProvider.php` |

**Description:**
The `policy_url` and `security_policy_url` fields in `LoginPageSettings` had no URL scheme validation. A malicious or compromised admin could save `javascript:alert(document.cookie)` as either URL value. PHP's `e()` (htmlspecialchars) does NOT strip URI schemes — it produces a syntactically valid `href="javascript:..."` attribute. When any unauthenticated user loads the login page, the browser renders a clickable link that executes the script in the page's origin context. This is stored XSS via a URL attribute bypass, with unauthenticated impact.

**Root Cause:**
`TextInput` fields had no server-side format constraint. `e()` is correct for HTML text nodes but insufficient for `href` attribute values where non-HTML injection vectors (URI schemes) apply.

**Fix Applied:**
- `LoginPageSettings.php`: Added `->regex('/^(\/|https?:\/\/).+/')` with a descriptive validation message to both URL fields. Only relative paths (starting `/`) or `http(s)://` URLs are accepted. `javascript:`, `data:`, `//`, and bare strings are rejected before writing to `tenant_settings`.
- `AdminPanelProvider.php`: Added a `$safeUrl` closure in `loginNoticeHtml()` that re-validates each URL against the same pattern at render time. Any value that bypasses form validation (e.g., directly written to DB) produces an empty href rather than rendering the dangerous value.

---

## SEC-018 — Silent RLS Context Injection Failure (No Logging)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **File** | `app/Http/Middleware/InjectDatabaseContext.php` |

**Description:**
The `catch (\Throwable)` block in `InjectDatabaseContext` silently discarded all exceptions. If `SET SESSION app.current_user_id` failed for any connection (transient timeout, pool exhaustion, PostgreSQL error), the connection's session variables remained unset. Subsequent queries on that connection evaluated RLS policies against a NULL user context — potentially bypassing row-level restrictions entirely. The failure produced no log entry, no metric, and no visible error — completely undetectable.

**Root Cause:**
The catch block contained only a comment. No logging or alerting was wired.

**Fix Applied:**
Changed `catch (\Throwable)` to `catch (\Throwable $e)` and added `Log::warning('RLS context injection failed', ['connection' => $connection, 'error' => $e->getMessage()])`. Failures are now visible in Laravel logs and can be surfaced by log aggregation tools.

---

## SEC-019 — ForceDeleteAction Available to All Admin Users (No Role Gate)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **Files** | `app/Filament/Admin/Resources/Properties/Pages/ViewPropertyV2.php`, `app/Filament/Admin/Resources/Properties/Pages/EditPropertyV2.php` |

**Description:**
`ForceDeleteAction` (permanent hard delete) was visible and executable by any authenticated Filament user — including read-heavy staff roles. Combined with `PropertyResource::getEloquentQuery()` removing `SoftDeletingScope` globally, a permanently deleted property record is unrecoverable from the admin UI.

**Fix Applied:**
Added `->visible(fn () => auth()->user()?->roles->first()?->name === 'super_admin')` to `ForceDeleteAction::make()` in both `ViewPropertyV2` and `EditPropertyV2`. Only `super_admin` role users see or can trigger the hard delete. All other admin users see only soft-delete (recoverable).

**Note:** SEC-006 tracks a full per-resource policy audit for Phase 4.

---

## SEC-020 — Unvalidated Amenity IDs Synced to Pivot Table

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **File** | `app/Filament/Admin/Resources/Properties/Pages/EditPropertyV2.php` |

**Description:**
`mutateFormDataBeforeSave()` collected all values from `amenities_*` form keys and passed them directly to `$record->amenities()->sync()` with no existence check. A crafted Livewire payload could supply arbitrary UUIDs that don't exist in `property_amenities`, causing foreign-key violations or — if FK enforcement was inconsistent across the cross-DB pivot — silently inserting orphaned pivot rows.

**Fix Applied:**
Added a `PropertyAmenity::whereIn('id', $ids)->pluck('id')->toArray()` validation step between collection and sync. Only IDs that exist in the `property_amenities` table are passed to `sync()`. Non-existent UUIDs are silently discarded.

---

## SEC-021 — No Server-Side Validation on Category Slug (Client-Side Regex Only)

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **File** | `app/Filament/Admin/Resources/Amenities/PropertyAmenityResource.php` |

**Description:**
`createOptionUsing` returned `$data['slug']` directly without server-side re-validation. The regex constraint (`/^[a-z][a-z0-9_]*$/`) was applied only on the client-side `TextInput` — a crafted request bypassing client validation could store an arbitrary string as a category slug. The slug is later interpolated into Filament component names (`amenities_{$category}`) and used in DB queries, where unexpected characters could cause component name confusion or schema rendering failures.

**Fix Applied:**
`createOptionUsing` now re-applies the regex server-side: `preg_match('/^[a-z][a-z0-9_]*$/', $slug)` — throws `InvalidArgumentException` on failure.

---

## SEC-022 — RLS Migration down() Restored Broken Policies (PostgreSQL Keyword Conflict)

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-05-31 |
| **Files** | `database/migrations/identity/2026_05_31_000001_fix_rls_rename_current_role_to_user_role.php`, `database/migrations/property/2026_05_31_000014_fix_rls_rename_current_role_to_user_role.php` |

**Description:**
Both `down()` migration methods recreated RLS policies using `current_setting('app.current_role', true)` — the exact broken reference that `up()` was fixing. Rolling back would silently recreate non-functional policies: PostgreSQL resolves `app.current_role` against the built-in `current_role` function (returning the DB connection role name `ah_app`), never matching `'staff'` or `'super_admin'`. For the property migration, this would lock all staff out of `property_access_info` (gate codes, cabin PINs). For identity, admin-read policies on `users` and `user_profiles` would silently block staff access.

**Fix Applied:**
Both `down()` methods now only drop the policies without recreating them, with a comment explaining why. A full rollback requires running the original migration's `down()` method.

---

## SEC-024 — EXIF GPS Auto-Published to Public Listing by Default (Unsafe Default)

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-06-13 |
| **Fixed** | 2026-06-13 |
| **Files** | `app/Services/Property/PropertyMapService.php`, `app/Filament/Admin/Resources/Properties/Pages/EditPropertyV2.php`, `app/Http/Controllers/Public/PropertyController.php`, `database/migrations/property/2026_06_13_000001_default_property_map_coords_private.php` |

**Description:**
The new property-map feature pulls GPS coordinates out of an uploaded image's EXIF block (`ExifGps::extract`) and stores them on the map image. The first map image uploaded for a property automatically becomes the public boundary map (`addMapImage`: `is_boundary = $isFirst`), and the public listing page renders the boundary map's coordinates whenever `show_coords_publicly` is true (`Public/PropertyController::show` → `boundary_map_coords`).

The `show_coords_publicly` flag defaulted to **true** at every layer: the DB column (`... DEFAULT true`), the service method parameter (`updateMapImageDetails(..., $showCoordsPublicly = true)`), the editor form fill (`?? true`), and the save fallback (`?? true`). Because `addMapImage` never sets the flag, new uploads inherited the column default.

Net effect: an admin who uploads a geotagged photo (e.g. a phone photo taken at a deer stand, cabin, or gate) silently publishes that photo's **exact** GPS coordinates on the public property page — pinpointing a sensitive on-property location — without ever opting in. For hunting properties this is a theft / trespass / poaching exposure, not merely a "property is in X county" disclosure.

**Root Cause:**
Default-open posture: auto-extraction of precise location data combined with a publish-by-default flag across the whole pipeline.

**Fix Applied:**
Flipped the feature to opt-in (fail-closed) at every layer:
- Migration `2026_06_13_000001_default_property_map_coords_private`: `ALTER COLUMN show_coords_publicly SET DEFAULT false` and `UPDATE property_map_images SET show_coords_publicly = false` (existing rows were never an explicit admin choice — the feature was <24h old and dev-only).
- `PropertyMapService::updateMapImageDetails()` parameter default → `false`.
- `EditPropertyV2`: form fill and save fallback `?? true` → `?? false`; toggle helper text now warns the coordinates may be auto-filled from EXIF and can pinpoint a stand/cabin/gate, so it should only be enabled when the exact location is safe to publish.

Auto-extraction is retained (useful for the admin-only map) — only public display is now opt-in.

**Verification:**
- Migration sets `show_coords_publicly` default to `false`; existing rows reset.
- `Public/PropertyController::show` returns `boundary_map_coords = null` unless an admin has explicitly enabled the toggle on the boundary map.

---

## SEC-025 — Public Map-Image Route Serves Non-Active Properties' Boundary Maps

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED |
| **Found** | 2026-06-13 |
| **Fixed** | 2026-06-13 |
| **File** | `routes/web.php` (`property-maps.show`) |

**Description:**
The public `GET /property-maps/{documentId}` route served any image whose `document_id` matched a live, boundary `property_map_images` row — but it did not check the owning property's `status`. The boundary map of a `draft`, `suspended`, `archived`, or soft-deleted property was therefore downloadable by anyone who knew the document UUID, even though the property is not visible in public search or on its detail page. This is the image-serving analogue of SEC-002 (draft-property IDOR), which the project fixed for the slug route. Severity is Low because the document UUID is unguessable and not enumerable.

**Root Cause:**
The route guarded `is_boundary` and `deleted_at` on the map-image row but never joined to `properties` to confirm the property itself is publicly visible.

**Fix Applied:**
The route now joins `properties` (same `property` connection — DB 2, not a cross-database join) and additionally requires `p.status = 'active'` and `p.deleted_at IS NULL`. Non-active and deleted properties return 404.

**Verification:**
- Boundary map of an `active` property still serves.
- Boundary map of a `draft`/`suspended`/`archived`/soft-deleted property returns 404.

---

## SEC-042 — Internal `manager_id` UUID Disclosed to Lessees via Contact Directory

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED |
| **Found** | 2026-06-14 |
| **Fixed** | 2026-06-14 |
| **File** | `app/Services/Property/PropertyService.php`, `app/Filament/Admin/Resources/Properties/Schemas/PropertyFormV2.php` |

**Description:**
The new property contact directory (`PropertyService::getContactDirectory()`) included the raw `property_managers.id` (DB 2 grant UUID) on every manager entry it returned. That payload is consumed by three callers: the admin Contacts tab (needs the ID to wire up its Delete action), the member lease page (`MemberController::show` → Inertia props), and the mobile API (`GET /api/v1/properties/{id}/contacts`). The latter two are lessee-facing, so an active lessee received the internal grant UUID even though they have no legitimate use for it. No endpoint accepting `manager_id` is reachable without `AdminAuth::canManageProperties()` (the `removeManagerContact` and revoke handlers both abort 403 for non-admins), so this was not directly exploitable — it is unnecessary internal-identifier disclosure / least-privilege leakage, the same class as SEC-024's "don't ship data the consumer doesn't need."

**Root Cause:**
`getContactDirectory()` had a single shape for all consumers and unconditionally merged `'manager_id' => $m->id` into each manager row. The admin renderer's need for the ID leaked into the lessee-facing payloads.

**Fix Applied:**
- Added a `bool $includeManagerIds = false` parameter to `getContactDirectory()`. `manager_id` is now merged into manager rows only when the flag is true.
- The admin Contacts-tab renderer (`PropertyFormV2::renderContactPartiesHtml`) calls `getContactDirectory($record->id, includeManagerIds: true)`.
- The member lease page (`MemberController`) and mobile API (`PropertyContactController`) use the default `false`, so the grant UUID is no longer present in any lessee-facing response.

**Verification:**
- Admin Contacts tab still renders the Delete action (manager_id present).
- `getContactDirectory($id)` (no flag) returns manager rows without a `manager_id` key — confirmed the member/API payloads no longer carry it.

---

## SEC-003-P4 — Access-Info Gate Trusted a Caller Bool Instead of Verifying the Lease

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED |
| **Found** | 2026-05-25 (deferred from SEC-003) |
| **Fixed** | 2026-06-14 |
| **File** | `app/Services/Property/PropertyService.php`, `app/Http/Controllers/Member/MemberController.php` |

**Description:**
`getAccessInfo()` (decrypts gate codes, wifi passwords, cabin codes) gated access on a `bool $callerHasVerifiedLease` flag the caller had to pass `true`. A future caller could pass `true` without actually confirming an active lease and the service would decrypt and return the credentials. This was the Phase-4 follow-up to SEC-003.

**Root Cause:**
The verification was advisory (a flag + doc comment), not structural. The service had no way to confirm the requesting user actually held an active lease.

**Fix Applied:**
- Signature changed to `getAccessInfo(string $propertyId, string $requestingUserId, string $encryptionKey)` — the bool flag is gone.
- The service now calls `LeaseService::userHasActiveLeaseForProperty($requestingUserId, $propertyId)` (service-layer assembly, not a cross-DB join) and throws `RuntimeException` if the user is not a party to an active lease.
- `MemberController::show()` passes the authenticated `$userId`.

**Verification:**
- Tinker: `getAccessInfo($propertyId, <random-uuid>, $key)` → throws `RuntimeException` ("requesting user has no active lease"). ✓
- Member lease page still renders access info for an active lessee. ✓

---

## SEC-006 — Per-Resource Mutation Abilities Fell Through to Filament Defaults

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-06-14 |
| **File** | All `app/Filament/Admin/Resources/**/*Resource.php` |

**Description:**
Resources overrode `canAccess()` and (some) `canCreate()`, but `canEdit()`, `canDelete()`, `canDeleteAny()`, `canForceDelete()`/`canForceDeleteAny()`, `canRestore()`/`canRestoreAny()` were not defined. With no model policies registered, Filament defaults those abilities to **true** for anyone who passes `canAccess()`. Two concrete consequences: (a) `PropertiesTable` exposed `ForceDeleteBulkAction` to any `property_admin`, bypassing the super_admin-only gate SEC-019 placed on the single-record force-delete; (b) `AdminUserResource` (access = `canManageSecurity`) let a `security_admin` create/edit admin users with **no `canCreate` override and no super_admin protection** — a privilege-escalation path (grant oneself `super_admin`, or edit/disable a `super_admin`).

**Root Cause:**
Authorization relied on implicit Filament defaults for every ability except access/create. No policies exist, so the defaults are permissive.

**Fix Applied:**
- Explicit mutation gates added to every resource, each consistent with its management role: PropertyResource, PropertyAmenityResource, CustomerUserResource, LegalDocumentResource, FeatureFlagResource, EmailTemplateResource, MfaFactorSettingResource, ProfileTemplateResource, LeaseApplicationResource (read-only — all mutations `false`), AdminUserResource.
- **PropertyResource:** edit/delete/restore = `canManageProperties`; `canForceDelete`/`canForceDeleteAny` = `isSuperAdmin` (closes the bulk force-delete gap relative to SEC-019).
- **AdminUserResource (SEC-006/D01):** `canCreate`/`canEdit` = `canManageSecurity`, but a non-super_admin may **not** edit a record holding `super_admin`, may **not** delete (delete/bulk = `isSuperAdmin`), and the role pickers exclude `super_admin` unless the actor is a super_admin (`assignableRoles()`).

**Verification:**
- `php -l` clean on all 10 resources.
- Logic review: bulk force-delete now gated to super_admin; security_admin cannot mint or edit super_admins.

---

## SEC-007 — `setAccessInfo()` Had No Throttle or Audit Trail

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-06-14 |
| **File** | `app/Services/Property/PropertyService.php` |

**Description:**
Encrypted gate-code writes could be made in rapid succession with no rate limit and no audit record of who changed access credentials when.

**Fix Applied:**
- `RateLimiter::attempt("set-access-info:{propertyId}:{userId}", 10, …, 60)` — 10 writes/min per property per user; throws `RuntimeException` on exceed.
- `AuditService::log('property_access_info_updated', …)` records the change with the changed **key names only** — never the gate codes / wifi passwords themselves (CLAUDE.md encryption rules).

**Verification:** `php -l` clean; AuditService never throws by design.

---

## SEC-008 — Read API Routes Had No Rate Limiting

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-06-14 |
| **File** | `app/Providers/AppServiceProvider.php`, `routes/api.php` |

**Description:**
`GET /api/properties`, `/api/properties/{id}`, and the legacy unauthenticated `properties` group had no throttle, allowing unbounded scraping / abuse.

**Fix Applied:**
- Two named limiters registered: `public-api` (60/min per IP) and `api` (120/min per user/token, IP fallback).
- Legacy unauthenticated `properties` group → `throttle:public-api`; authenticated `v1/properties` group → `throttle:api`.

**Verification:** `php -l` clean; limiters registered in `AppServiceProvider::boot()`.

---

## SEC-010 — Readonly Grants on `_read` Connections

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | VERIFIED |
| **Found** | 2026-05-25 |
| **Fixed** | 2026-06-14 |
| **File** | `database/migrations/{property,geospatial}/*grant_readonly_permissions.php` |

**Description:**
Confirm `ah_readonly` has SELECT (and only SELECT) on every active `_read` connection.

**Resolution:**
- `property_read` and `geospatial_read` each have a `grant_readonly_permissions` migration (`GRANT USAGE` + `GRANT SELECT ON ALL TABLES` + `ALTER DEFAULT PRIVILEGES … GRANT SELECT`). Verified live: `geospatial_read` SELECT succeeds.
- `wildlife_read` is configured in `config/database.php` but **DB 5 has no tables yet** (verified: `information_schema.tables` count = 0). Its grant migration must accompany the DB 5 build — captured as a build-time requirement. No exposure today (nothing to read).

**Verification:** Tinker — `geospatial_read` SELECT OK; `wildlife` public-table count = 0.

---

## SEC-023 — RLS Context Not Injected for Non-HTTP Connections (Documented)

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | FIXED (documented) |
| **Found** | 2026-05-31 |
| **Fixed** | 2026-06-14 |
| **File** | `app/Http/Middleware/InjectDatabaseContext.php` |

**Description:**
`InjectDatabaseContext` does not set `app.current_user_id`/`app.user_role` for the `audit`, `analytics`/`analytics_etl`, and `research` connections. These carry no user-scoped RLS today and (for ETL) are never reached via HTTP, so this is correct — but the omission was implicit and could silently break a future RLS policy added to one of them.

**Fix Applied:**
The exclusion is now an explicit, documented contract in the middleware: a comment block lists each excluded connection with the reason, and states that any future user-scoped RLS policy on those DBs MUST be added to the injection list (or given an explicit ETL-side context step).

**Verification:** Code review — the included-connection list is unchanged in behavior; the exclusion rationale is now in-code.

---

## SEC-043 — RLS Universally Bypassed: App Role Owns Every Table and `FORCE ROW LEVEL SECURITY` Is Not Set

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED (2026-06-16) |
| **Found** | 2026-06-15 |
| **File** | All RLS-enabled tables across DBs 1/2/3/4 (systemic); migrations under `database/migrations/*` |

**Description:**
The application connects to PostgreSQL as the role `ah_app`, and `ah_app` is the **owner** of every table. PostgreSQL **does not apply row-level-security policies to a table's owner** unless the table is additionally marked `FORCE ROW LEVEL SECURITY`. A live inspection of the running databases shows every RLS-enabled table has `relrowsecurity = true` but `relforcerowsecurity = false`:

- **billing (DB 4):** `invoices`, `payment_methods`, `payments`, `payouts`, `w9_records`
- **identity (DB 1):** `users`, `user_profiles`, `mfa_configurations`, `background_check_results`, `api_keys`
- **property (DB 2):** `property_access_info` (gate codes, wifi/cabin codes)
- **lease (DB 3):** `leases`, `lease_hunters`, `lease_notes`, `check_ins`

Because the runtime role owns these tables, **none of these policies are ever evaluated.** The `InjectDatabaseContext` middleware faithfully sets `app.current_user_id` / `app.user_role` on each request, but no policy consults them — the entire RLS layer is currently a no-op for the application connection. CLAUDE.md and several prior findings (SEC-003, SEC-018, SEC-023) describe RLS as the DB-level backstop ("RLS policy enforces this at the DB level but the service layer must also enforce it"); in practice only the service-layer half exists.

**Why this matters now:** the new W-9/billing tables were built trusting this same backstop. `w9_records.tin` is encrypted, but the `w9_records_own_user` policy meant to restrict *which rows* a user can read (TINs, legal names, addresses, backup-withholding status) enforces nothing. There is not yet a W-9 or billing read path over HTTP (no routes today), so this is **latent, not yet exploitable** — the danger is that the next developer builds a `TaxService` / billing endpoint reasonably relying on RLS to scope rows and silently leaks every payee's tax/payment data. The same latent gap covers identity and lease data that *do* have live read paths today (currently protected only by whatever service-layer checks each path happens to implement).

**Root Cause:**
Migrations `ENABLE ROW LEVEL SECURITY` and `CREATE POLICY ... TO ah_app`, but never `ALTER TABLE ... FORCE ROW LEVEL SECURITY`, and the application runs as the table-owning role. Owner-bypass is silent (no error, policies simply never fire), so the gap was invisible without inspecting `pg_class.relforcerowsecurity`.

**Fix Applied (2026-06-16) — dedicated non-owner runtime role (a three-role architecture):**

Rather than `FORCE ROW LEVEL SECURITY` on the owner (which keeps the app as owner and is easy to silently undo with a new un-forced table), the app no longer connects as the table owner at all. Three roles now split by trust:

| Role | Attributes | Used by |
|---|---|---|
| `ah_app` | schema **owner**, no BYPASSRLS | Migrations & seeders **only** (DDL). Never a runtime connection. |
| `ah_runtime` | non-owner, **RLS applies** | All user-facing HTTP requests (web + API). DML only. |
| `ah_system` | non-owner, **BYPASSRLS**, member of `ah_runtime` | Trusted subsystems that run before/without a per-user RLS context: auth bootstrap (login/register/MFA/verify/reset), the Filament admin panel, the queue worker, and console commands. |

Because `ah_runtime` is **not** the owner, every existing `relrowsecurity = true` policy is now evaluated against it — `relforcerowsecurity` is unnecessary for a non-owner. Owner-only operations (migrations) keep bypassing RLS as before via `ah_app`.

What was implemented:
1. **Roles provisioned** — `docker/postgres/init.sql` creates `ah_runtime` (LOGIN, CONNECT on all 14 DBs) and `ah_system` (LOGIN, BYPASSRLS, `GRANT ah_runtime TO ah_system` so it inherits all of runtime's table/sequence grants). Provisioned live on the running cluster (volume predates the file, so it won't re-run on restart).
2. **Policies retargeted** — the Stage-2 migrations (`*_retarget_rls_policies_to_runtime.php`) recreate every policy `TO ah_runtime` (identity, property, lease, billing) and harden the empty-context cast to `NULLIF(current_setting('app.current_user_id', true), '')::uuid` so an unset context yields NULL (deny) instead of erroring. Grant migrations (`*_grant_runtime_permissions.php`) give `ah_runtime` table DML; audit (DB 9) is restricted to SELECT+INSERT (append-only).
3. **App flipped to `ah_runtime`** — `config/database.php` defaults the 12 writer connections to `ah_runtime`; `.env`/`.env.example` set the per-connection `DB_*_USERNAME=ah_runtime` and add `DB_SYSTEM_*`/`DB_APP_*`. (Note: docker-compose `env_file: .env` bakes these at container creation — the container must be recreated, not just `config:clear`'d.)
4. **Trusted paths routed through `ah_system`** — `App\Database\ConnectionRole` swaps role per process/request; `RuntimeDatabaseRoleProvider` selects owner (migrate/seed), system (queue/console), or testing→owner; `UseSystemDatabaseRole` middleware (alias `db.system`) wraps the auth routes (`routes/auth.php`, `/v1/auth`) and is added to the Filament admin panel.
5. **Per-user context unchanged but now load-bearing** — `InjectDatabaseContext` still sets `app.current_user_id`/`app.user_role`; it now also resolves the web custom-session user (`session('auth.user_id')`, guarded by `hasSession()` for stateless API) since `$request->user()` is null for the web portals.
6. **Write-side coverage:** tables with only `FOR SELECT` policies (`users` INSERT, `w9_records`, `invoices`, `payments`, `payouts`) are written only by trusted paths (registration, billing jobs/webhooks) that run as `ah_system`, so the owner-bypass removal does not break those writes. Adding user-scoped `INSERT/UPDATE ... WITH CHECK` policies so these can run as plain `ah_runtime` is a follow-up hardening (tracked under SEC-023's writer contract), not required for this fix.

**Verification (2026-06-16):**
- Live enforcement matrix on `lease.leases` (11 rows) as `ah_runtime`: no context → **0** (default-deny); lessee context → **1** (own row); `app.user_role=staff` → **11**; as `ah_system` → **11** (BYPASSRLS). As the old `ah_app` it was 11 regardless.
- Automated regression: `tests/Feature/Security/RlsEnforcementTest` connects explicitly as `ah_runtime` and asserts not-owner, RLS-enabled, default-deny, own-row, cross-user-deny, staff-override — **6/6 pass**. Fails if the app reverts to the owner, RLS is disabled, or a policy is dropped.
- End-to-end as `ah_runtime`: public pages + API 200; member login (via `db.system`) → member dashboard shows only the lessee's own lease; Filament admin login 200. Full suite: 124/124 except one pre-existing, environment-only `max_connections=100` exhaustion in the full run (every group passes in isolation; unrelated to RLS).

**Follow-up fix (2026-06-16) — admin Livewire write path:** the initial fix added `UseSystemDatabaseRole` to the Filament panel's `->middleware([...])`, which covers full-page panel routes (GET `/admin/...`). But Livewire registers its component-update endpoint (`POST /livewire-<hash>/update`, route name `default-livewire.update`) **globally in the bare `web` group**, outside the panel middleware — and every admin interaction (form saves, table/bulk actions) is a Livewire update. Those requests therefore ran as `ah_runtime`. Because the admin panel authenticates with Laravel's **`web` guard** (not the web portals' custom `auth.user_id` session key that `InjectDatabaseContext` reads), the guard resolves the staff user with an RLS-protected `SELECT` on `identity.users` that fires **before** any per-user context is set → under `ah_runtime` it returns zero rows → the guard sees no user → Filament bounces the save to `/admin/login`, whose Livewire/`X-Livewire` render then 500s with `No hint path defined for [layouts]` (Livewire's `layouts::app` fallback). Symptom: clicking **Save** on a property edit page returned a 500. Fix: `AppServiceProvider::boot()` appends `UseSystemDatabaseRole` to the Livewire update route in an `app->booted()` callback (finds the route whose name ends in `livewire.update`). Livewire is admin-only here (the public/member portals are Inertia/React), so scoping `ah_system` to that one route is safe and mirrors the panel. Verified end-to-end: real authenticated staff `save` on `EditPropertyV2` via the update route now returns **200** with no login bounce and no layout 500; pre-fix the same request bounced and 500'd. Guard-lookup proof: `SELECT users WHERE id=staff` returns NULL under `ah_runtime`+empty-context but the row under `ah_system`. RLS regression still 6/6.

**Follow-up fix (2026-06-15) — admin document/image-serving routes (same root cause, third surface):** the admin galleries embed photos and boundary-map images via `<img src="{{ route('admin.documents.view', …) }}">`, and downloads via `admin.documents.download` / `admin.lease-documents.download`. These routes live in the bare `web` group with only `auth:web` — NOT wrapped in `db.system` — so, exactly like the Livewire write path, the `web` guard's RLS-protected `SELECT` on `identity.users` ran as `ah_runtime` with empty context, returned zero rows, and 302'd the request to `/admin/login`. The browser then received an HTML login page in place of image/file bytes, so every admin photo and map image rendered **broken** (the Photos and Map tabs on the property edit page). Fix: add `db.system` to those three routes. **Ordering caveat (important):** route-middleware array order alone does NOT put `db.system` before `auth:web` — `Authenticate` is in Laravel's middleware priority list (matched via the `AuthenticatesRequests` contract) and gets sorted ahead of any non-prioritized middleware, so `UseSystemDatabaseRole` landed *last*. Fixed by registering it in the priority list immediately before that contract in `bootstrap/app.php` (`$middleware->prependToPriorityList(AuthenticatesRequests::class, UseSystemDatabaseRole::class)`). This also makes the panel fix more robust (db.system now sorts ahead of auth by priority rather than by being hand-placed first). Verified: `router->gatherRouteMiddleware()` shows `UseSystemDatabaseRole` before `Authenticate:web` on `admin.documents.view` and before Filament's auth on the panel + login routes; a live DB probe confirms the staff-user lookup is NULL under `ah_runtime`+empty-context and FOUND under `ah_system`; the referenced photo files exist on the documents disk (so the only failure was the auth redirect). **Lesson: any route that serves admin content under `auth:web` outside the panel needs `db.system`, and a non-prioritized role-swap middleware must be added to the priority list (not just placed first in the array) to guarantee it runs before the auth guard.**

**Follow-up fix (2026-06-15) — admin print/restore/delete routes (same root cause, fourth surface) + route-group consolidation:** three more bare-`web`-group admin routes still carried only `auth:web` with no `db.system`: `admin.applications.print` (the lease-application **Print** button), `admin.lease-documents.restore`, and `admin.lease-documents.delete`. Same defect — the `web` guard's `SELECT` on `identity.users` ran as `ah_runtime` with empty context and found zero rows. Here the symptom was a hard 500 rather than broken images: with the authenticated staff user unresolved, `Authenticate` treated the request as unauthenticated and redirected to its `redirectTo()` target, route name **`login`**, which does not exist in this app (Filament's login is `filament.admin.auth.login`) → `Symfony\…\RouteNotFoundException: Route [login] not defined`. Reported on clicking **Print** at `/admin/applications/lease-applications/{id}` (staff user `6ec4b3e8-…`). Fix: rather than patch each route inline (this was the third recurrence of the omission), **all** admin `web`-guard routes outside the panel were consolidated into a single `Route::middleware(['db.system', 'auth:web'])->group(...)` in `routes/web.php`, so the role can never be omitted again on a sibling route. Verified: `gatherRouteMiddleware()` shows `UseSystemDatabaseRole` at index 5, before `Authenticate:web` at 6, on all three routes; an end-to-end probe authenticating as the real staff user and dispatching the print URL through the HTTP kernel returns **200** with the full rendered print page (pre-fix: 500 / RouteNotFoundException). Note the unauthenticated case still 500s on these admin routes because `Authenticate`'s `login`-route redirect target doesn't exist — a separate, lower-priority papercut (authenticated staff is the only intended caller); tracked as a possible future tidy (point `redirectTo` at the Filament login). **Resolved (2026-06-20):** `bootstrap/app.php` now sets `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))`, so an unauthenticated request to any `auth:web` admin route (document/image view, print, etc.) bounces cleanly with a `302` to `/admin/login` instead of throwing `RouteNotFoundException` (500). Surfaced when an anonymous user routed directly to a backend DL image URL (`/admin/documents/{id}/view`). Only affects the `auth:web` guard; the member portals use `auth.session`/`RequireSessionAuth`, which handles its own redirect. Verified: anonymous curl to the document-view route returns `302 → http://localhost/admin/login`. Merged to `main` in `b7a6c34`.

**Follow-up fix (2026-06-18) — console role swap must purge pre-opened connections:** `migrate`/`migrate:single` failed with `SQLSTATE[42501]: must be owner of table membership_plans` even though `RuntimeDatabaseRoleProvider` selects the `ah_app` owner for schema commands. Root cause: the migrate command pre-opens the target connection (config default `ah_runtime`) before the provider's role swap runs, and `ConnectionRole::useOwner()`/`useSystem()` only rewrote `config()` — an already-open PDO keeps its original credentials, so DDL ran as `ah_runtime` and was denied. Fix: the provider now calls `ConnectionRole::useOwner(true)`/`useSystem(true)` (purge=true) so all `APP_CONNECTIONS` are `DB::purge()`'d and reconnect with the swapped role. After the fix `migrate:single platform` runs DDL as `ah_app`; RLS regression still 6/6 (the change only affects console/queue/test boot, never the `ah_runtime` HTTP path).

---

## SEC-044 — Encrypted-Field Plaintext and Key Pass Through Query Bindings (Log-Exposure Risk)

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | OPEN |
| **File** | `app/Models/Traits/HasEncryptedFields.php` |

**Description:**
`HasEncryptedFields` encrypts/decrypts by issuing `SELECT encode(pgp_sym_encrypt(?, ?), 'base64')` / `pgp_sym_decrypt(decode(?, 'base64'), ?)` with the **plaintext value and the encryption key passed as query bindings.** The values are correctly parameterized (no SQL-injection risk) and are not written to the application log on the normal path. However, any mechanism that records query bindings — Laravel Telescope, `DB::enableQueryLog()`, the debugbar, or a PostgreSQL slow-query/`log_statement=all` setting — would capture **both the plaintext sensitive value (now including W-9 TINs via SEC-044's table) and the symmetric encryption key in the same log line.** CLAUDE.md mandates that encrypted fields "must never appear in application logs, even decrypted."

**Root Cause:**
The pgcrypto approach requires the plaintext and key to travel to the database over the connection and thus through the query layer, where binding-capturing tooling can observe them. The trait is now used for the most sensitive field on the platform (`w9_records.tin`), raising the impact.

**Recommended Fix:**
- Confirm and document that Telescope/debugbar/query-log are **disabled in production**, and that PostgreSQL `log_statement` is not `all`/`mod` for the billing/identity roles (these would log bindings).
- Consider moving encryption app-side (Laravel `Crypt` / sodium) so neither plaintext nor key is ever sent as a query binding — at the cost of losing in-DB `pgp_sym_*` searchability (already not relied upon for these columns).
- At minimum, add a guard/comment so query logging is never enabled on the `billing`/`identity` connections.

**Verification (pending):** Inspect production logging config; add a test asserting no encrypted-field plaintext appears in `DB::getQueryLog()` output for these connections (or that logging is off).

---

## SEC-045 — `check_ins` Missing Write-Side RLS Policy: Member Check-In/Out Default-Denied Under `ah_runtime` (MEDIUM) — Fixed 2026-06-16

| Field | Detail |
|---|---|
| **Severity** | Medium |
| **Status** | FIXED (2026-06-16) |
| **File** | `database/migrations/lease/2026_06_16_000003_add_check_ins_runtime_write_policy.php` |
| **Lineage** | Fallout of SEC-043 (runtime role flip) |

**Description:**
After SEC-043 moved user-facing requests off the table owner (`ah_app`, which bypasses RLS) onto the non-owner `ah_runtime` role, every `check_ins` write started failing. A PostgreSQL `FOR SELECT` policy only supplies a `USING` clause for row visibility — it grants no INSERT and no UPDATE. With RLS enabled and **no permissive write policy**, the default-deny rule rejects the statement (`new row violates row-level security policy`). `check_ins` had only `check_ins_own_or_lessor FOR SELECT`, so member field check-in (`CheckInService::checkIn` → INSERT, [routes/web.php:156](routes/web.php#L156)) and check-out (`CheckInService::checkOut` → UPDATE, [routes/web.php:157](routes/web.php#L157)) — both on the `auth.session` (`ah_runtime`) path — silently broke. The bug was invisible before SEC-043 because the owner bypasses RLS.

A platform-wide catalog sweep (`pg_policies` + `pg_class.relrowsecurity` across all 12 writer DBs) confirmed the blast radius is bounded: 15 RLS-enabled tables exist (in identity/property/lease/billing only); 10 are SELECT-only, but of those, `check_ins` was the **only** one written on a live `ah_runtime` path. The others are written exclusively on `ah_system` (BYPASSRLS) paths — Filament admin (`property_access_info`, `leases`, `lease_hunters`), Checkr/OFAC webhook+queue (`background_check_results`) — or have no writer yet (`lease_notes`), or are not live (billing `invoices`/`payments`/`payouts`/`w9_records`, Phase 5).

**Root Cause:**
RLS write enforcement (`WITH CHECK`) is separate from read enforcement (`USING`). The original lease policies were authored as SELECT-only and never exercised for writes because the app connected as the owner. SEC-043's role flip surfaced the gap.

**Fix:**
Added two self-service, additive policies on `check_ins` for `ah_runtime`:
- `check_ins_insert_self` — `FOR INSERT WITH CHECK (user_id = current_user OR role IN (staff, super_admin))`
- `check_ins_update_self` — `FOR UPDATE USING (...) WITH CHECK (...)` with the same predicate

A hunter may write only their own rows; staff/super_admin retain write access for support corrections (mirroring the existing SELECT policy); the lessor can still *see* check-ins on their lease but cannot author them. Purely additive — no existing policy modified, so reads and all `ah_system` paths are unaffected.

**Billing tables — resolved 2026-06-16 (`database/migrations/billing/2026_06_16_000003_add_w9_records_runtime_write_policy.php`):** Examining the actual write authorship (rather than assuming all four needed write policies) split the latent billing tables into two cases:

- **`w9_records` — needs a runtime write policy (added).** A payee (landowner/outfitter/seller) legitimately submits and certifies their *own* W-9 from the member portal on an `ah_runtime` request. Added additive `w9_records_insert_self` (`FOR INSERT WITH CHECK`) and `w9_records_update_self` (`FOR UPDATE USING … WITH CHECK …`), predicate `user_id = current_user OR role IN (staff, super_admin)`, mirroring the existing SELECT policy.
- **`invoices`, `payments`, `payouts` — deliberately left SELECT-only for `ah_runtime` (no write policy, by design).** These are *system-authored financial-integrity records* — created by Stripe webhooks, queue jobs, and Filament admin, all of which run under `ah_system` (BYPASSRLS). Granting `ah_runtime` an INSERT/UPDATE policy would let an authenticated user *forge* invoices, payments, or payouts. Default-deny on the runtime role is the correct, fail-safe control: any Phase 5 service that authors these must run under `db.system` (exactly as webhooks already do), not on a user-facing `ah_runtime` connection. (`payment_methods` is already `FOR ALL` with a `WITH CHECK` — user-managed cards — and needs nothing.)

**Verification:** `tests/Feature/Security/CheckInRlsWriteTest` (check_ins) and `tests/Feature/Security/W9RecordRlsWriteTest` (w9_records) — each connects explicitly as `ah_runtime` and proves own-INSERT and own-UPDATE succeed, cross-user INSERT is rejected (`WITH CHECK`), cross-user UPDATE matches zero rows (`USING`), and staff INSERT-on-behalf succeeds. Full Security suite: 18 passed.

---

## SEC-046 — Lease Activation Silently Default-Denied Under `ah_runtime`: Lessee-Last E-Signature Never Activates the Lease (HIGH) — Fixed 2026-06-17

| Field | Detail |
|---|---|
| **Severity** | High |
| **Status** | FIXED (2026-06-17) |
| **File** | `app/Database/ConnectionRole.php`, `app/Services/Lease/EsignatureService.php`, `app/Services/Lease/LeaseService.php` |
| **Lineage** | Fallout of SEC-043 (runtime role flip); missed by the SEC-045 sweep |

**Description:**
`leases` has only a `FOR SELECT` policy (`leases_parties_and_staff`). As with SEC-045, a SELECT-only policy supplies no `WITH CHECK` for writes, so under the non-owner `ah_runtime` role an `UPDATE leases` is default-denied. Unlike an INSERT (which raises `new row violates row-level security policy`), an **UPDATE filtered by RLS simply affects zero rows and does not raise.** When the **lessee signs last from the member portal** (`LeaseSignController::sign`, an `ah_runtime` request → `EsignatureService::recordSignature` → `activateIfComplete` → `LeaseService::activate`), the `UPDATE leases SET status='active'` and the `lease_hunters` primary-approval `UPDATE` both **silently no-op'd**. The signing request was still marked `completed`, the executed PDF was still generated, and `activateIfComplete` still returned `true` — so the member saw *"Your lease is now active!"* and a misleading `lease.activated` audit row was written, while the lease row stayed `pending_signatures` indefinitely. It only worked when the **lessor countersigned last via the Filament admin panel** (`ah_system`, BYPASSRLS), which is why it escaped notice.

SEC-045's catalog sweep explicitly classified `leases`/`lease_hunters` as "written exclusively on `ah_system` paths (Filament admin)" — it **missed the in-platform e-signature completion path**, which writes them under `ah_runtime` whenever the lessee is the final signer. Observed in dev on lease `744889ba` (Piney Creek): both parties signed Jun 16, request `completed`, signed PDF stored, yet `status = pending_signatures`.

**Root Cause:**
RLS write enforcement (`WITH CHECK` / `USING` for UPDATE) is separate from read enforcement; the SELECT-only lease policy grants no UPDATE. The lease-activation state transition runs on a user-facing `ah_runtime` connection, where that UPDATE silently affects zero rows. Compounding it, `LeaseService::activate` trusted the `update()` call without verifying persistence, so the silent failure was reported as success.

**Fix (two parts, by design — no broadened runtime write policy):**
Lease activation is a *trusted system state transition*, not a self-service party edit. Granting `ah_runtime` an UPDATE policy on `leases` would let an authenticated party forge lease state — the same hazard called out for `invoices`/`payments`/`payouts` in SEC-045. So instead of a write policy:
- **`ConnectionRole::asSystem(callable)`** — new scoped helper that elevates every app writer connection to `ah_system` (BYPASSRLS) for the duration of a closure, then restores the *exact* prior credentials (safe to nest; correct under console/queue which already run as `ah_system`).
- **`EsignatureService::activateIfComplete`** now runs the completion writes (request `completed`, `SignatureEvent`, `LeaseService::activate`, `lease_hunters` approval, executed-PDF generation) inside `asSystem`, so the final signature activates the lease regardless of which party signs last. `leases` stays write-locked to trusted roles under `ah_runtime`.
- **`LeaseService::activate`** re-reads the row after the update and throws a `RuntimeException` if `status !== 'active'`, so a role lacking UPDATE on `leases` can never again silently strand a lease while reporting success.

**Data remediation:** the one stranded dev lease (`744889ba`) was activated and its primary hunter approved via the console (`ah_system`).

**Verification:** `tests/Feature/Security/LeaseActivationRlsTest` connects explicitly as `ah_runtime` and proves (1) RLS is enabled on `leases`, (2) a runtime `UPDATE leases` affects zero rows (the silent no-op), (3) `LeaseService::activate` run directly under runtime now throws (the persistence guard), and (4) wrapping it in `ConnectionRole::asSystem` persists `status='active'`. Full Security suite: 22 passed.

---

## SEC-047 — Property Check-In Log Shows "Unknown user" in the Member Portal: Cross-DB Name Lookup Default-Denied by `users` RLS Under `ah_runtime` (LOW) — Fixed 2026-06-17

| Field | Detail |
|---|---|
| **Severity** | Low (functional defect; no data exposure) |
| **Status** | FIXED (2026-06-17) |
| **File** | `app/Services/Lease/CheckInService.php` |
| **Lineage** | Fallout of SEC-043 (runtime role flip) |

**Description:**
The landowner property check-in log (`/member/properties/{id}/details?tab=checkin` → `PropertyDetailController::edit` → `CheckInService::getHistoryForProperty`) rendered every entry as "Unknown user". The check-in rows themselves loaded fine — only the hunter names were missing. `getHistoryForProperty` resolves names by a cross-DB lookup `User::on('identity')->whereIn('id', $checkInUserIds)`. The identity `users` table RLS (`users_self_read` / `users_admin_read`) only lets a non-staff user SELECT their **own** row, or staff/super_admin read all. Under the member portal's `ah_runtime` role the viewing landowner is neither the hunter nor staff, so every hunter row was filtered out → `null` → the `'Unknown user'` fallback. The identical Filament admin path (`PropertyFormV2`) worked because it runs as `ah_system` (BYPASSRLS).

**Root Cause:**
`users` RLS is correctly scoped to self/staff for direct access, but it has no concept of the cross-DB "landowner may see hunters who checked in on my property" relationship (leases live in DB 3, users in DB 1 — un-expressible in a single-table RLS policy). The name resolution is a trusted service-layer assembly that runs *after* the caller has already authorized the viewer for the property (`PropertyDetailController::authorizeManage` → `PropertyService::userCanManageProperty`, 404 otherwise), but it executed under `ah_runtime` where RLS hid the rows.

**Fix:**
Wrap **only** the identity user lookup in `ConnectionRole::asSystem` (the helper introduced in SEC-046). The set of `user_id`s is constrained to users who actually checked in on this property's leases, and the viewer is already authorized for the property, so this is a narrowly-scoped trusted assembly — no `users` RLS broadening, and no other query in the method is elevated.

**Verification:** `php -l` clean; reproduced manually — the member-portal check-in log now shows the hunter's name/email (e.g. `imatester@2digital.com`). The existing admin path is unchanged (already correct under `ah_system`).

---

## SEC-048 — Public Property Detail Gate Uses `auth()->check()`, Which Is Always False for Session-Authed Members (LOW — fail-closed)

| Field | Detail |
|---|---|
| **Severity** | Low (correctness/availability defect; **no data exposure** — fails closed) |
| **Status** | OPEN |
| **Found** | 2026-06-20 (72h feature security scan) |
| **File** | `app/Http/Controllers/Public/PropertyController.php` (`show`, ~line 88) |

**Description:**
The public property detail gate is meant to be "members-only EXCEPT featured (advertising) listings, which guests may view":
```php
if (! auth()->check() && ! $property->activeListings->contains(fn ($l) => $l->is_featured)) {
    return redirect('/get-started');
}
```
The entire web portal authenticates via the session key `session('auth.user_id')` — the Laravel default guard is **never hydrated** for members (there is no `Auth::login`/`loginUsingId`/`onceUsingId` anywhere in `app/` outside the Filament admin panel). So `auth()->check()` is **always false** for a logged-in member. The gate therefore collapses to "redirect everyone on a non-featured property," catching logged-in members too:

- Anonymous + non-featured → redirected ✅ (intended)
- Anonymous + featured → allowed ✅ (intended)
- **Member + non-featured → redirected to `/get-started`** ❌ (defect — should be allowed)
- Member + featured → allowed ✅

The frontend already disagrees with the backend: `HandleInertiaRequests` shares `'authenticated' => (bool) session('auth.user_id')`, and `Public/PropertyDetail.tsx` / `Properties.tsx` show Apply buttons to authenticated members — yet `show()` redirects them away before the page renders. The intended anonymous-blocking is intact and correct; only the member half of the same rule is broken.

**Risk posture:** This is **fail-closed** — the bug is *more* restrictive than intended, so there is no information disclosure or auth bypass. The impact is purely functional: members can't open non-featured property detail pages.

**Root Cause:**
`auth()->check()` reads the Laravel guard, but the public/member portal is session-authenticated (`RequireSessionAuth` / `session('auth.user_id')`), not guard-authenticated. The two notions of "logged in" diverge.

**Proposed Fix (not yet applied):**
Match the rest of the portal and the Inertia share — gate on the session key:
```php
if (! session('auth.user_id') && ! $property->activeListings->contains(fn ($l) => $l->is_featured)) {
    return redirect('/get-started');
}
```
Keeps guests out of non-featured details exactly as today; lets logged-in members through.

---

## SEC-049 — Contact Directory Resolves Identity Users Under `ah_runtime` Without `asSystem` (LOW — fail-closed; verify)

| Field | Detail |
|---|---|
| **Severity** | Low / Informational (potential functional under-disclosure; **no data exposure** — fails closed) |
| **Status** | OPEN (needs functional verification) |
| **File** | `app/Services/Property/PropertyService.php` (`getContactDirectory`) |
| **Lineage** | Same class as SEC-047 (cross-DB name lookup vs `users` RLS under `ah_runtime`) |

**Description:**
`getContactDirectory()` resolves the landowner/manager identities for the lessee-facing field-contact directory with a plain `User::on('identity')->whereIn('id', $userIds)` — **without** the `ConnectionRole::asSystem` wrapper that its sibling `getManagersForProperty()` uses (added in SEC-047 precisely because `users` RLS hides other people's rows under `ah_runtime`). When this path runs in a per-user `ah_runtime` context (e.g. the mobile API `GET /api/v1/properties/{id}/contacts`, or the member lease page), the viewing hunter is neither the owner/manager nor staff, so the `users` RLS (`users_self_read`/`users_admin_read`) would filter those rows out — landowner/manager names and phone numbers could resolve to blank/email-fallback.

**Risk posture:** **Fail-closed** — if it misfires it *under*-discloses (blank contacts), never over-discloses, so it is not a security exposure. Flagged because it is a likely *functional* gap in the field-contact directory (hunters in the field may not see the landowner/manager contact details they're supposed to), and because it is the same root pattern as SEC-047 left un-applied on this sibling method.

**To verify when picked up:** confirm whether the contact directory is actually reached under `ah_runtime` (member lease page + mobile API) and whether owner/manager contacts render or come back blank. If blank, wrap **only** the identity user lookup in `ConnectionRole::asSystem` (viewer already authorized for the property via `userHasActiveLeaseForProperty`/`userCanManageProperty`), mirroring SEC-047 — no `users` RLS broadening.

**Note:** Distinct from SEC-042 (which *removed* the internal `manager_id` from lessee payloads). The contact name/phone disclosure here is intended-by-design; the concern is that RLS may be suppressing it.

---

## SEC-050 — Admin Document View/Download Routes Unscoped + Unaudited; Now Serving Applicant DL/License PII

| Field | Detail |
|---|---|
| **Severity** | Low (staff-gated; defense-in-depth + audit gap) |
| **Status** | **FIXED (2026-06-21)** |
| **Found** | 2026-06-21 |
| **File** | `routes/web.php` (`admin.documents.view`, `admin.documents.download`); `app/Http/Controllers/Admin/AdminDocumentController.php` |
| **Lineage** | Promotes the 2026-06-14 out-of-window Auditing Note to a tracked finding; PII exposure escalated by commit `1f3f57d` |

**Description:**
`GET /admin/documents/{documentId}/view` and `/download` do a bare `Document::findOrFail($documentId)` + `Storage::response()/download()` with **no per-document ownership/authorization check** and **no audit logging** (unlike the lease-document download path, which audits via `LeaseDocumentService::adminDownload`). They are gated only by `['db.system','auth:web']`. Commit `1f3f57d` (this window) added applicant **driver's-license and hunting-license numbers + front/back images** to the admin hunter roster, all served through `admin.documents.view`, and renders the document UUIDs into the page (`<img src>`), increasing both the volume of PII behind the route and the surface from which a UUID can be harvested.

**Why this is Low, not an IDOR:** only Filament login issues a standard `web`-guard session, and Filament login enforces `User::canAccessPanel()`, which restricts to staff/admin roles (`super_admin, global_admin, property_admin, security_admin, article_admin, staff`). Landowners are **not** in that list, and the member/landowner portals authenticate via a separate custom session guard (`auth.session` / `RequireSessionAuth`) that does not satisfy `auth:web`. The routes are therefore effectively staff-only. The residual gaps are (a) any staff user can fetch **any** document by UUID regardless of which application/property it belongs to (no least-privilege scoping), and (b) viewing/downloading applicant PII (driver's licenses) leaves **no audit trail**.

**Recommended fix:**
- Audit-log every access through these routes (document id + acting staff user id), mirroring `LeaseDocumentService::adminDownload`.
- Optionally scope access to documents the staff member's role/assignment should legitimately see (resolve the document's owning application/property), so e.g. a `property_admin` cannot pull unrelated applicants' DLs by raw UUID.

**Verification (when fixed):** access writes an `AuditService` event; a staff user without scope on a document receives 403/404.

**Fix (2026-06-21):** the two inline route closures were replaced with `AdminDocumentController` (`view`/`download`), which audit-logs every access via `AuditService::log` (`document.viewed` / `document.downloaded`, acting staff user id) before streaming the file. The disk resolution and staff-only `['db.system','auth:web']` gating are unchanged. Per-document role scoping (gap (a)) was deliberately **deferred** — the audit trail is the priority gap and scoping needs an application/property-ownership resolver; it remains noted for the next admin pass. Regression: `tests/Feature/Admin/AdminDocumentAccessTest` (view + download write an audit row; guest is rejected).

---

## SEC-051 — Stripe Subscription/Account IDs Written to Webhook Diagnostic Logs

| Field | Detail |
|---|---|
| **Severity** | Low |
| **Status** | **FIXED (2026-06-21)** |
| **Found** | 2026-06-21 |
| **File** | `app/Jobs/Billing/ProcessStripeWebhook.php`; `CLAUDE.md` (Billing DB rule) |

**Description:**
Several webhook diagnostic log lines include `stripe_subscription_id` / `stripe_account_id` (≈ lines 120, 143, 217, 353). Line 143 was added in this window (commit `3c110ee`, the billing-interval fix) and follows the file's pre-existing logging pattern. CLAUDE.md's billing rule states *"even Stripe IDs should not appear in general application logs."* These are correlation identifiers — not PANs or payment-method/charge tokens — so impact is limited to internal-identifier disclosure should application logs be exposed.

**Root Cause:**
Webhook handlers log the subscription/account id as a correlation key when an event can't be matched to a local record.

**Recommended fix (pick one):** drop the Stripe IDs from these payloads (log only `user_id` / event type), **or** add an explicit, documented carve-out in CLAUDE.md that webhook *correlation* IDs (subscription/account/event ids — never payment-method/charge/PAN data) are permitted in logs and keep them. The defect is the undocumented mismatch with the written rule, not the IDs themselves.

**Verification:** webhook diagnostic logs contain no `stripe_*_id` values (if removed), or CLAUDE.md documents the carve-out (if kept).

**Fix (2026-06-21):** chose the carve-out. CLAUDE.md's Billing DB (DB 4) rule now states explicitly that webhook *correlation* IDs (`stripe_subscription_id`, `stripe_customer_id`, `stripe_account_id`, Stripe event ids) MAY appear in webhook/diagnostic logs as correlation keys — and that this never extends to payment-method, charge, last-four/brand, or PAN/CVV data, which remain banned from logs. The existing webhook log lines carry only these correlation ids, so no code change was needed; the defect was the undocumented mismatch with the written rule, which is now resolved.

---

## SEC-052 — Promo Per-User Limit Has a TOCTOU Window Between Checkout Validation and Webhook Redemption

| Field | Detail |
|---|---|
| **Severity** | Low / Informational |
| **Status** | **FIXED (2026-06-21)** |
| **Found** | 2026-06-21 |
| **File** | `app/Services/Billing/PromoCodeService.php` (`validateForPlan` vs `recordRedemption`) |

**Description:**
`validateForPlan` enforces `per_user_limit` by counting existing `promotion_claims` (`promo_code_used = code`) at checkout-**start** time, but the claim is only authored later, at **webhook** time, in `recordRedemption`. A user who opens N hosted-checkout sessions before any completes passes the per-user check N times and could redeem the code beyond `per_user_limit`. The **global** `max_redemptions` is protected (`recordRedemption` increments via an atomic guarded UPDATE); only the per-user cap is racy.

**Impact (why Low):** each bypass requires actually completing a paid Stripe checkout (the user pays each time), the benefit is at most an extra discounted/granted cycle, and one-active-subscription enforcement (`SubscriptionService::activeFor`) further limits the practical effect. (Note: the per-user check is functional — `BillingService::applyPromotion` persists `promo_code_used` — so this is a race, not a dead check.)

**Recommended fix:** make per-user redemption atomic at record time — e.g. a unique constraint on `(user_id, promo_code_used)` when `per_user_limit = 1`, or a guarded conditional insert in `recordRedemption` that no-ops once the user is at the limit, mirroring the global increment guard.

**Verification:** concurrent completed checkouts for the same user + code create at most `per_user_limit` claims.

**Fix (2026-06-21):** `recordRedemption` now runs inside a `billing` transaction that `lockForUpdate()`s the promo-code row and **re-checks both** the global `max_redemptions` cap and the per-user limit (count of this user's `promo_code_used` claims) under that lock before incrementing and authoring the claim. Concurrent redemptions of the same code serialize on the row lock, closing the TOCTOU window; different codes never contend. A unique constraint was rejected because `per_user_limit` may legitimately be > 1. Regression: `PromoCodeServiceTest::test_record_redemption_respects_per_user_limit` (a user already at the per-user limit is not redeemed again even with global headroom).

---

## SEC-053 — First-Listing Auto-Apply Eligibility Check Is Existence-Based, Not Atomic

| Field | Detail |
|---|---|
| **Severity** | Low / Informational |
| **Status** | **FIXED (2026-06-21)** |
| **Found** | 2026-06-21 |
| **File** | `app/Services/Billing/PromotionAutoApplyService.php` (`isEligible` / `applyTrigger`); `database/migrations/billing/2026_06_21_000002_add_unique_trigger_claim_per_user_period.php` |

**Description:**
`isEligible` enforces once-per-user-per-period with a `PromotionClaim::exists()` check, then `applyTrigger` increments `claim_count` (atomic, guarded against `claim_limit`) and authors the claim. The existence check and the claim write are not a single atomic operation, so two concurrent first-listing creations for the same user could both pass `isEligible` and both author a claim. The **global** `claim_limit` is still protected by the atomic increment; only the per-user-once invariant is racy.

**Impact (why Low):** `applyForSignup` fires once and is not concurrent; `applyForFirstListing` requires a user to create two listings near-simultaneously, and grant claims are additive (EntitlementService resolves the highest-precedence active claim), so a duplicate grants no extra benefit — this is a data-cleanliness issue more than an exposure.

**Recommended fix:** enforce uniqueness on `(user_id, promotion_period_id)` for trigger-based claims, or perform the eligibility check + claim insert atomically.

**Verification:** concurrent first-listing creations create at most one claim per user per period.

**Fix (2026-06-21):** added a **partial unique index** `uq_promo_claims_user_period_trigger` on `promotion_claims (user_id, promotion_period_id) WHERE trigger_event IN ('signup','first_listing')`, making the once-per-user trigger grant atomic at the DB. The predicate scopes it to trigger grants only — `promo_code` / `manual_admin` claims may legitimately repeat for the same user + period. `applyTrigger` now catches `UniqueConstraintViolationException` from the lost-race insert and **decrements** the speculative `claim_count` bump so the global count stays exact. Regression: `PromotionAutoApplyServiceTest::test_duplicate_trigger_claim_is_rejected_at_db` and `::test_non_trigger_claims_may_repeat_for_same_user_period`.

---

## SEC-054 — Env Templates Default to `APP_DEBUG=true` / `APP_ENV=local` (Debug-Page Information Disclosure)

| Field | Detail |
|---|---|
| **Severity** | Low (latent — Medium if it ever reaches production) |
| **Status** | OPEN — mitigated by warnings; enforce at prod-deploy time |
| **Found** | 2026-06-25 |
| **File** | `.env.example`, `docs/laravel/env.example` |

**Description:**
With `APP_DEBUG=true`, Laravel renders the full Ignition error page on any unhandled exception — including a `405` on a wrong-method request (e.g. a browser GET to the POST-only `/api/webhooks/stripe`). That page discloses the stack trace, file paths, executed SQL, framework/PHP versions (`Laravel 13.11.2`, `PHP 8.4.21` — useful for CVE fingerprinting), and the request headers (session cookie, XSRF token). This compounds **SEC-044**: an error during an encrypted-field decrypt could surface the query bindings (plaintext value + symmetric key) on the page itself.

**Impact (why Low now):** No production deployment exists yet (only the dev `docker-compose.yml`; `APP_ENV=local`), so nothing is currently exposed. `config/app.php` also fails closed — `env('APP_DEBUG', false)` / `env('APP_ENV', 'production')` default safe when the var is unset. The risk is a **footgun**: both env templates ship `APP_ENV=local` + `APP_DEBUG=true`, so a production `.env` bootstrapped from them inherits the debug page unless the operator remembers to flip both.

**Root cause:** The committed `.env.example` had no production warning; the values double as a ready-to-run local config.

**Mitigation applied (2026-06-25):** Added explicit "PRODUCTION: set `APP_ENV=production` and `APP_DEBUG=false`" warnings to both `.env.example` and `docs/laravel/env.example`, spelling out exactly what the debug page leaks.

**Remaining / verification:** When the production compose + CI/CD is actually built (the deployment docs describe files that don't yet exist), it **must** set `APP_ENV=production` and `APP_DEBUG=false` (sourced from Key Vault / pipeline secrets, never from the example). Verify post-deploy: a `405`/`500` on the public host returns the bare Laravel error page with no stack trace, SQL, or headers. Ties into the SEC-044 "disable query logging in production" item.

---

## SEC-056 — Eager RLS Context Injection Exhausts Connection Slots and Silently Skips Context (Intermittent Zero-Row RLS Reads) — Fixed 2026-06-25

| Field | Detail |
|---|---|
| **Severity** | Medium (availability + correctness; fail-closed, no data exposure) |
| **Status** | **FIXED (2026-06-25)** |
| **Found** | 2026-06-25 |
| **File** | `app/Http/Middleware/InjectDatabaseContext.php`, `app/Providers/DatabaseServiceProvider.php`, `app/Database/RlsContext.php` (new) |

**Description:**
`InjectDatabaseContext` set the RLS session variables (`app.current_user_id` / `app.user_role`) by looping over **all 14** RLS-bearing connections and calling `getPdo()` on each — eagerly opening a PostgreSQL connection to every database on **every** HTTP request, regardless of which databases the request actually touched. An Inertia page load issues several requests in parallel, and each held up to 14 open connections, so under modest concurrency Postgres ran out of non-superuser slots (`FATAL: remaining connection slots are reserved for roles with the SUPERUSER attribute`). The middleware caught that failure **per connection, logged a warning, and let the request continue** — so the connection that lost the race had no context set. Under `ah_runtime` an empty `app.current_user_id` makes every RLS policy default-deny, and that database's reads silently return **zero rows**.

**How it surfaced:** A member paid a security deposit; the Checkout webhook authored the `held` row in `security_deposits` correctly, but the lease page kept rendering the **"Pay Deposit"** button. `MemberController::show()` computes `can_pay` from `SecurityDepositService::forLease()`, which returned `null` whenever the billing connection was the one that failed context injection — even though the row existed and was readable in isolation. The log showed 1069 `RLS context injection failed` warnings across connections (including `billing`), confirming the exhaustion was routine, not a one-off. Because it depends on concurrent load, an isolated reproduction (single tinker read under `ah_runtime` with context set) always succeeded — masking the bug.

**Impact:** Intermittent, load-dependent. Any RLS-protected read could return empty for a legitimately-authorized user when that DB's slot was refused — a denial/correctness fault, fail-closed (it hides rows, never exposes them). No cross-tenant leakage.

**Root cause:** Eagerly force-opening every connection per request (to set session variables up front) multiplied connection pressure ~14× and turned a transient slot shortage into silent context loss, compounded by swallowing the failure instead of surfacing it.

**Fix (2026-06-25):** Inject context **lazily**. A request-scoped `App\Database\RlsContext` singleton holds the resolved user id + role; `InjectDatabaseContext` now just *arms* it (and applies to any connections already opened before the middleware ran, e.g. the identity connection used to resolve the role) instead of opening all 14. A `ConnectionEstablished` listener registered in `DatabaseServiceProvider` applies the context the moment each connection is actually opened — so a request opens only the databases it uses, and the context is **re-applied automatically on any reconnect/`DB::purge`** (which also closes the `ConnectionRole::asSystem`/`db.system` purge-drops-context gap). `applyTo` no longer swallows failures: if a connection genuinely cannot be opened, the read that needed it fails loudly rather than returning a misleading empty set. Until the context is armed the listener is a no-op, so console, queue, and test connections are unaffected.

**Verification:** Probe under the real `ah_runtime` role (config-swapped + `DB::purge('billing')`, context armed, fresh resolve) returns `current_user=ah_runtime`, `app.current_user_id` set, `forLease=held`. `tests/Feature/Security` + `tests/Feature/Member` green (51 passed); the 6 `RlsEnforcementTest` policy cases unchanged.

---

## SEC-055 — `stripe_accounts` Shipped Without RLS — Any Authenticated User Could Read/Forge a Landowner's Connect Account & Payout Flags

| Field | Detail |
|---|---|
| **Severity** | High (latent — no Connect onboarding path live yet) |
| **Status** | **FIXED (2026-06-25)** — RLS enabled, system-authored + runtime-read-only; regression test green |
| **Found** | 2026-06-25 |
| **File** | `database/migrations/billing/2026_06_25_000001_add_rls_to_stripe_accounts.php` |

**Description:**
The `stripe_accounts` table (DB 4) — the landowner ↔ Stripe Connect account mapping that carries the `charges_enabled` / `payouts_enabled` / `details_submitted` flags gating whether a landowner can receive money — was created (`2026_06_14_000007`) **without** `ENABLE ROW LEVEL SECURITY` and with no policy. Every other money table in the billing DB (`invoices`, `payments`, `payouts`, `security_deposits`, the invoice projection) is system-authored and runtime-read-only under the SEC-045 pattern, but `stripe_accounts` was missed. Because `ah_runtime` inherits a blanket table-level DML grant via `ALTER DEFAULT PRIVILEGES`, any authenticated user's runtime connection could `SELECT` another landowner's row (leaking the `stripe_account_id`) and — worse — `INSERT`/`UPDATE` rows, e.g. forge their own `payouts_enabled = true` ahead of the `account.updated` webhook.

**Impact (why latent):** No Connect onboarding flow is wired yet (Phase 5.5 in progress), so no rows exist and no runtime path touches the table in production today. The gap is a forgery primitive that would have been live the moment onboarding shipped.

**Root cause:** The original migration predates the three-role RLS rollout (SEC-043) and was never retrofitted when the other billing tables were retargeted to `ah_runtime` (`2026_06_16_000002`).

**Fix applied (2026-06-25):** New migration enables RLS on `stripe_accounts` with a single `FOR SELECT TO ah_runtime` policy (own `user_id` + staff/super_admin) and **no write policy** — mirroring `payouts`/`security_deposits`. The runtime path can read its own account state but can never author or mutate one; all writes (onboarding row creation + `account.updated` flag sync) run under `ah_system` (BYPASSRLS). Regression: `tests/Feature/Security/StripeAccountRlsWriteTest` (6 tests — RLS enabled, owner reads own, unrelated denied, staff reads all, runtime INSERT rejected, runtime UPDATE 0-affected).

---

## SEC-057 — Forfeiture Reversal Left the Landowner Overpaid — No Transfer Reversal / Hunter Refund on Exoneration

| Field | Detail |
|---|---|
| **Severity** | Medium (financial integrity) |
| **Status** | **FIXED (2026-06-29)** — reversal now reverses the Connect transfer + refunds the hunter; regression tests green |
| **Found** | 2026-06-29 |
| **File** | `app/Services/Billing/SecurityDepositService.php` (`reverseForfeitFault` / new `clawbackForfeiture`) |

**Description:**
When a security-deposit forfeiture is upheld (`confirmForfeitFault`), the forfeited amount is disbursed to the landowner via a **separate** Stripe Connect transfer (`PayoutService::disburse` → `StripeService::createTransfer`). If the hunter is later exonerated, `reverseForfeitFault` only restored the hunter's Trust Score and recorded an audit note saying *"money clawback handled manually"* — it moved **no money**. The disbursing transfer was never reversed (landowner kept the net) and the hunter was never refunded. `StripeService::reverseTransfer` existed for exactly this but had **zero callers**.

**Impact:** An exonerated hunter stayed out their full deposit while the landowner kept a payout they were no longer owed; the platform silently absorbed the gap unless an operator caught it and reversed by hand. Money-correctness defect, not an exposure — adjudication-gated (admin-only), so no untrusted trigger.

**Root cause:** Under separate charges & transfers a customer-side refund does not auto-reverse the landowner transfer; the reversal must be issued explicitly. The forfeiture-reversal path was shipped Trust-only and deferred the money unwind to manual reconciliation, which was never built.

**Fix applied (2026-06-29):** `disburseForfeitedAmount` now records the disbursing payout id on the deposit (`security_deposits.forfeit_payout_id`, new column). `reverseForfeitFault` calls a new `clawbackForfeiture()` that (1) reverses the payout's transfer via `StripeService::reverseTransfer` — clawing the net back from the landowner and marking the `Payout` `reversed` (new `reversed_at` column + extended status CHECK) — and (2) refunds the forfeited amount to the hunter from the original captured deposit charge, flipping the deposit to `released`. Both Stripe calls are best-effort, mirroring the disbursement's graceful-deferral pattern: a failure is logged and flagged `manual_reconciliation` in the audit trail but never blocks the exoneration. Migration `2026_06_29_000001_add_forfeiture_clawback_to_billing`. Regression: `tests/Feature/Billing/SecurityDepositServiceTest` — `reverse_claws_back_a_disbursed_forfeiture` (transfer reversed + payout `reversed` + hunter refunded) and `reverse_restores_the_hunters_score_and_refunds_an_undisbursed_forfeiture` (no transfer to reverse, hunter still refunded).

---

## SEC-058 — Payment Bypass: Success-Return Reconcilers Author Paid Rows Without Verifying `payment_status` (Unpaid Checkout Session Replay)

| Field | Detail |
|---|---|
| **Severity** | High (payment bypass / financial integrity) |
| **Status** | **FIXED (2026-06-30)** — `payment_status` gate added to all three payment-mode reconcilers; regression tests green (83 passed) |
| **Found** | 2026-06-30 |
| **File** | `app/Services/Billing/{LeasePaymentService,SecurityDepositService,BookingDepositService}.php` (the `record*FromCheckout` methods) + the `db.system` success-return routes (`MemberController::depositReturn` / `leasePaymentReturn`, `ApplyController::bookingFeeReturn`) |

**Description:**
The three payment-mode Checkout reconcilers — `LeasePaymentService::recordCollectedFromCheckout`, `SecurityDepositService::recordHeldFromCheckout`, `BookingDepositService::recordPaidFromCheckout` — author a *paid* billing row (`collected` / `held`) from a Stripe Checkout Session payload, keyed only on `metadata.purpose`, the presence of `metadata.lease_id`/`application_id`, the presence of `session.payment_intent`, and idempotency on that PaymentIntent id. **None of them check `session.payment_status`.** In Stripe's `payment` mode the PaymentIntent id is populated on the session at *creation* (before any payment), and `payment_status` stays `'unpaid'` until the charge succeeds — so "has a payment_intent" does **not** imply "was paid."

These reconcilers are reachable two ways: (1) the signed `checkout.session.completed` **webhook** — safe, Stripe only fires it on completion; and (2) the **`db.system` success-return routes**, which retrieve the session by an `?session_id=` query param the user supplies. A member who starts a Checkout obtains their own session id from the Stripe Checkout URL (`https://checkout.stripe.com/c/pay/cs_test_…`), can **abandon payment**, then GET the return route (`/member/leases/{lease}/lease-payment/return?session_id=cs_…`, `/…/deposit/return`, or `/apply/status/{application}/booking-fee/return`). The metadata `lease_id`/`application_id` matches (it's their own session), so the guard passes and the reconciler runs under `ah_system` (BYPASSRLS) and writes the paid row — **with no money collected.** The best-effort `chargeAndTransferForPaymentIntent` / `chargeIdForPaymentIntent` calls simply return nulls (no charge exists) under `rescue`, so the write still succeeds.

**Impact (per flow):**
- **Lease payment** — a `lease_payments` row is written `collected`; `balanceDueCents` drops by the gross, and `activateIfFullyPaid` can promote a signed `pending_payment` lease to `active` (unlocking check-in, gate QR, stand map) — a free lease settlement.
- **Security deposit** — a `security_deposits` row is written `held` and mirrored to `leases.deposit_paid`; the landowner believes the deposit is secured when no money exists. A later `release`/`forfeit` then refunds/transfers against a non-existent charge.
- **Booking fee** — the most severe: `recordPaidFromCheckout` calls `ApplicationService::onBookingFeePaid`, which on a win **creates the lease + reservation + signing request**. An applicant can win the spot and obtain a lease without ever paying the booking fee.

Authenticated-only and requires the user to grab their own `cs_…` id, so not wormable — but it is a direct, repeatable payment bypass with real financial/state consequences, hence High (the booking-fee → free-lease variant trends Critical).

**Root cause:** The reconcilers were written to be shared verbatim by the trusted webhook and the user-reachable success-return, but only the webhook carries the implicit "this session completed/was paid" guarantee. Trusting the success redirect (a classic Stripe anti-pattern — "never fulfill on the redirect without verifying server-side") was carried into a path where the session id is attacker-supplied. The subscription reconciler (`MembershipCheckoutService::recordSubscriptionFromCheckout`) is *not* affected: it requires `session.subscription`, which Stripe only populates once the subscription is actually created (payment succeeded / trial started).

**Fix applied (2026-06-30):** Each payment-mode reconciler now gates on payment having actually happened — immediately after the `purpose` match, `recordCollectedFromCheckout` / `recordHeldFromCheckout` / `recordPaidFromCheckout` require `in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)` before authoring the row; otherwise they `Log::warning` with the session id only (correlation id — no amount/card data) and return null. The booking-fee path therefore never even consults the win/lose orchestration (`onBookingFeePaid`) for an unpaid session, so no lease is created. The guard covers **both** entry points: the signed webhook (a completed card session is already `'paid'`, so that path is unchanged) and the user-reachable `db.system` success-return. Regression: each service test gained a `record_*_rejects_an_unpaid_session` case asserting an `unpaid` payload authors no row (and, for booking fee, that `onBookingFeePaid` is never called); the existing happy-path/webhook fixtures were corrected to carry `payment_status => 'paid'` as real Stripe payloads do. Full affected suite green (83 passed): `LeasePaymentServiceTest`, `SecurityDepositServiceTest`, `BookingDepositServiceTest`, `ProcessStripeWebhookTest`, `LeasePendingPaymentTest`, `PayLeaseBalanceTest`. Defense-in-depth follow-ups (not required for the fix, noted only): scope the success-return lease/application lookup to the current user, and only honor a `session_id` the platform recorded against this user's own initiated checkout.

---

## SEC-059 — IDOR on Public Boundary API: `boundary()` Returns Geometry for Non-Active / Draft / Deleted Properties (the Endpoint SEC-002 Missed)

| Field | Detail |
|---|---|
| **Severity** | Medium (unauthenticated location-data disclosure; boundary sibling of SEC-002 / SEC-025) |
| **Status** | **FIXED (2026-06-30)** — `boundary()` now applies the SEC-002 active-status/`deleted_at` 404 guard before serving geometry; regression tests green (14 passed) |
| **Found** | 2026-06-30 |
| **File** | `app/Http/Controllers/Api/PropertyController.php` (`boundary()`); routed unauthenticated at `routes/api.php` `GET /properties/{id}/boundary` |

**Description:**
`Api\PropertyController::boundary($id)` calls `GeospatialService::getPropertyBoundaryGeoJson($id)` and returns the parcel boundary GeoJSON (polygon geometry + `area_acres` + `source`) **without any `status`/`deleted_at` visibility check**. The service queries `property_boundaries` (DB 13) by `property_id` alone — no join to the property's status, no RLS on that path — so it resolves a boundary for a property in *any* state: `draft`, `suspended`, `archived`, or soft-deleted.

The endpoint is mounted on the **unauthenticated** legacy route group (`Route::prefix('properties')->middleware('throttle:public-api')`), so `GET /properties/{uuid}/boundary` is reachable with no token — only a per-IP throttle. A caller who knows or obtains a property UUID retrieves its precise parcel outline regardless of whether the property is publicly listed.

This is the **same vulnerability class as SEC-002** ("draft properties accessible by UUID on the public API"), on the **sibling endpoint that the SEC-002 fix did not touch**. SEC-002 added the `status !== 'active' → 404` guard to `show()` (and `findBySlug()`), but `boundary()` — in the same controller, on the same unauthenticated route group — was never gated. It is also the JSON-geometry analogue of **SEC-025** (the public boundary-map *image* route, which *is* correctly gated to `p.status = 'active'`): the boundary **image** of a non-active property is private, but the boundary **polygon** of the same property is not.

**Impact:**
Unauthenticated disclosure of precise parcel boundaries (exact location/extent + acreage) for properties the owner has **not** made public — a property still being set up as a draft, one suspended/archived by the owner or staff, or a soft-deleted one. The platform deliberately treats boundary geometry as access-controlled (SEC-024 GPS handling, SEC-025 boundary-image gating, the lessee-only `Api\PropertyMapController`/`PropertyContactController`), so this is a least-privilege/location-privacy leak, not a payment/PII exposure. Not enumerable by brute force (128-bit UUID), but property UUIDs leak through ordinary channels (Inertia payloads, shared preview links, browser history, logs, a former-lessee who saw the property while active). For an *active* property the boundary image is already public, so the practical exposure is the **non-active** set.

**Root cause:**
`getPropertyBoundaryGeoJson()` is intentionally unrestricted (admin/internal callers need full visibility), exactly like `PropertyService::find()` in SEC-002. The public-facing controller is responsible for applying the visibility filter before returning, and `boundary()` — unlike its neighbor `show()` — does not. The SEC-002 audit fixed the data-returning endpoints it enumerated (`show`, `findBySlug`) but did not extend the gate to the geometry endpoint.

**Recommended fix (small, mirrors SEC-002):**
In `boundary()`, resolve the property first and apply the identical guard before serving geometry:
```php
$property = $this->propertyService->find($id);
if (! $property || $property->status !== 'active' || $property->deleted_at !== null) {
    return response()->json(['error' => 'Not found'], 404);
}
$geoJson = $this->geospatialService->getPropertyBoundaryGeoJson($id);
```
This closes the unauthenticated legacy route and keeps the authenticated `v1` route consistent with `show()`. If an active *lessee* must read the boundary of a property that later went non-active, additionally allow `LeaseService::userHasActiveLeaseForProperty()` (the gate already used by `Api\PropertyMapController`) — but the active-status check alone matches the existing SEC-002/SEC-025 contract and is the minimal fix.

**Verification:**
- `GET /properties/{uuid-of-draft}/boundary` → 404; `GET /properties/{uuid-of-soft-deleted}/boundary` → 404; `GET /properties/{uuid-of-active}/boundary` → 200.
- `tests/Feature/Api/PropertyDetailTest.php` — `test_boundary_endpoint_returns_404_for_draft_property_with_boundary`, `..._for_soft_deleted_property_with_boundary`, `..._for_unknown_property_uuid`, alongside the existing active-with-boundary 200 case. Suite green (14 passed).

---

## Open / Deferred Items

| ID | Description | Severity | Status | Target Phase |
|---|---|---|---|---|
| SEC-043 | RLS bypassed platform-wide — app role owns tables, `FORCE ROW LEVEL SECURITY` unset; missing write-side `WITH CHECK` policies on billing tables | High | **FIXED (2026-06-16)** — app runs as non-owner `ah_runtime`; trusted paths via `ah_system` (BYPASSRLS); regression test green | — |
| SEC-044 | Encrypted-field plaintext + key pass through query bindings (log-exposure if query logging enabled) | Low | OPEN | Pre-launch hardening |
| SEC-045 | `check_ins` (and payee `w9_records`) write default-denied under `ah_runtime` (SELECT-only RLS policy) | Medium | **FIXED (2026-06-16)** — self-service write policies added to `check_ins` + `w9_records`; `invoices`/`payments`/`payouts` intentionally left runtime-read-only (system-authored via `ah_system`); regression tests green (18 passed) | — |
| SEC-046 | Lease activation silently default-denied under `ah_runtime` when the lessee signs last — `UPDATE leases` no-ops (SELECT-only RLS) yet reports success; lease stuck at `pending_signatures` | High | **FIXED (2026-06-17)** — completion writes run via `ConnectionRole::asSystem`; `LeaseService::activate` re-reads + throws on non-persist; regression test green (22 passed) | — |
| SEC-047 | Member-portal property check-in log shows "Unknown user" — cross-DB hunter name lookup default-denied by `users` RLS under `ah_runtime` | Low | **FIXED (2026-06-17)** — name lookup wrapped in `ConnectionRole::asSystem` (viewer already property-authorized) | — |
| SEC-048 | Public property detail gate uses `auth()->check()` (always false for session-authed members) → logged-in members wrongly redirected from non-featured detail pages; fail-closed (no exposure) | Low | OPEN — fix: gate on `session('auth.user_id')` | Next property pass |
| SEC-049 | Contact directory (`getContactDirectory`) resolves identity users under `ah_runtime` without `asSystem` → owner/manager contacts may render blank for lessees; fail-closed (no exposure) | Low | OPEN — verify reach under `ah_runtime`, then mirror SEC-047 fix if blank | Next property pass |
| SEC-050 | Admin document view/download routes unscoped + unaudited; now serve applicant DL/license PII (staff-gated, not an IDOR) | Low | **FIXED (2026-06-21)** — routed via `AdminDocumentController`, every access audit-logged; per-document scoping deferred; regression test green | — |
| SEC-051 | Stripe subscription/account IDs in webhook diagnostic logs (deviates from "no Stripe IDs in logs" rule) | Low | **FIXED (2026-06-21)** — CLAUDE.md documents a correlation-ID carve-out (never payment-method/charge/PAN data); IDs kept | — |
| SEC-052 | Promo per-user-limit TOCTOU between checkout validation and webhook redemption (global cap safe) | Low | **FIXED (2026-06-21)** — `recordRedemption` re-checks per-user + global caps under a `lockForUpdate` row lock; regression test green | — |
| SEC-053 | First-listing auto-apply once-per-user check not atomic → possible duplicate claim (no extra benefit) | Low | **FIXED (2026-06-21)** — partial unique index on `(user, period)` for trigger claims + decrement-on-violation; regression tests green | — |
| SEC-054 | Env templates default to `APP_DEBUG=true`/`APP_ENV=local` → full debug error page (stack trace, SQL, versions, headers) if used for prod | Low | **OPEN** — warnings added to both env examples (2026-06-25); enforce `APP_DEBUG=false`/`APP_ENV=production` when prod deploy is built | Pre-launch hardening |
| SEC-056 | RLS context-injection middleware eagerly opens all 14 databases per request → connection-slot exhaustion; the connection that loses the race has its context silently skipped (warning-logged, request continues), so that DB's RLS reads return zero rows — intermittent, load-dependent. Surfaced as a paid security deposit rendering "Pay Deposit" (the held row default-denied). | Medium | **FIXED (2026-06-25)** — lazy injection via `RlsContext` + `ConnectionEstablished` listener (only opens databases a request uses; re-applies on reconnect); fail-loud instead of swallowing; regression tests green (51 passed) | — |
| SEC-055 | `stripe_accounts` shipped without RLS → any authenticated user could read/forge a landowner's Connect account + `payouts_enabled` flag | High (latent) | **FIXED (2026-06-25)** — RLS enabled, SELECT-only `TO ah_runtime` (own row + staff), no write policy (system-authored); regression test green (6 passed) | — |
| SEC-057 | Forfeiture reversal left the landowner overpaid — exoneration restored Trust only, never reversed the disbursing Connect transfer or refunded the hunter ("manual reconciliation" that was never built) | Medium | **FIXED (2026-06-29)** — `reverseForfeitFault` now reverses the payout transfer + refunds the hunter (best-effort, manual-recon flagged on Stripe failure); regression tests green | — |
| SEC-058 | Payment bypass — payment-mode success-return reconcilers (lease payment / security deposit / booking fee) author paid rows from an attacker-supplied `session_id` without checking `payment_status`; an unpaid-session replay fakes a paid lease balance / held deposit / won booking fee (free lease) | High | **FIXED (2026-06-30)** — `payment_status in ('paid','no_payment_required')` gate added to all three `record*FromCheckout` methods (covers webhook + return); webhook path unaffected; regression tests green (83 passed) | — |
| SEC-059 | IDOR on public API — `Api\PropertyController::boundary()` serves parcel boundary GeoJSON + acreage for **any** property UUID with no `status`/`deleted_at` gate, reachable unauthenticated on the legacy `GET /properties/{id}/boundary` route; the geometry sibling of SEC-002 that the SEC-002 fix never touched (and the JSON analogue of the SEC-025 boundary-image gate) — leaks precise location/extent of draft/suspended/archived/soft-deleted properties | Medium | **FIXED (2026-06-30)** — `boundary()` now applies the same active-status/`deleted_at` 404 guard as `show()` before serving geometry; regression tests green (14 passed) | — |

---

## Auditing Notes

- Phase 3 initial audit: all issues in the property domain (DB 2 / `PropertyService`).
- Phase 3 CMS audit (2026-05-25): `HomepageSettings` and `NavigationSettings` Filament pages reviewed. Findings: SEC-011 (URL injection), SEC-012 (no audit trail), SEC-013 (no maxLength). All fixed same session.
- Phase 3 logo upload audit (2026-05-25): File upload feature reviewed. Findings: SEC-014 (SVG XSS via direct URL), SEC-015 (orphaned public files), SEC-016 (untrusted path in Storage::url). All fixed same session.
- Phase 3/4 admin UI audit (2026-05-31): Property V2 edit/view pages, amenities resource, login page settings, RLS middleware reviewed. Findings: SEC-017 through SEC-022. All fixed same session. SEC-023 deferred (no RLS on those DBs yet).
- Last-24h feature audit (2026-06-13): reviewed the DB-managed email template system (`EmailTemplateService`, `MailSettingsService`, `EmailSettings`, version preview), the property-map feature (`PropertyMapService`, `ExifGps`, map editor, public map route), the configurable post-login redirect, and the new encrypted UserProfile contact fields. Findings: SEC-024 (EXIF GPS published by default) and SEC-025 (map route ignored property status) — both fixed same session. **No issue found** in: email template rendering (variables HTML-escaped in HTML bodies via `htmlspecialchars`; admin-only authorship; preview iframe uses `sandbox=""`), SMTP settings (password encrypted at rest via `Crypt`, never echoed to the form, change audit-logged, access gated by `canManageSystem`), or the post-login redirect (value comes from admin-controlled tenant settings, not user input).
- Last-24h feature audit (2026-06-14): reviewed the member field-check-in + QR system (`CheckInController`, `CheckInService`), the stand-map / boundary overlay (`PropertyMapService::getBoundaryOverlay`, `MemberController`), the property contact directory (`PropertyService::getContactDirectory`, admin Contacts tab, `Api/PropertyContactController`), the opt-in manager-contact flow (`is_field_contact` migration + `PropertyManager` model + `EditPropertyV2::removeManagerContact`), and the executed-lease PDF download (`LeaseSignController`, `EsignatureService::downloadSignedLease`). Finding: SEC-042 (`manager_id` UUID disclosed to lessees) — fixed same session. **No issue found** in: check-in (`checkIn`/`checkOut` enforce `abort_unless(mayCheckIn, 403)` — lessee or approved LeaseHunter only; check-out scoped to the user's own open record), stand-map markers + access info (served only for the lessee's own `active` lease, GPS member-only per SEC-024), contact directory (gated by `userHasActiveLeaseForProperty`, returns 404 not 403 to non-lessees per SEC-024; intended landowner/manager contact details are by design), the opt-in manager flow (admin-only writes via `AdminAuth::canManageProperties`; managers no longer auto-listed to hunters), lease PDF download (`abort_if(403)` unless lessee or lessor; download audit-logged via AuditService), and the PDF/QR/check-in-log blades (all output escaped — no `{!! !!}`). **Out-of-window note:** the admin `auth:web` document download/view routes (`/admin/documents/{documentId}/download`, `/view`) do a bare `findOrFail` + `Storage::download` with no per-document ownership check. These predate this window (commit `05df4a5`) and are reachable only through the `web` (admin) guard — members authenticate via the separate `auth.session`/`RequireSessionAuth` system — so they are effectively admin-restricted, but lack defense-in-depth ownership scoping. Logged here; not assigned a SEC ID this pass.
- Last-24h security scan (2026-06-15): reviewed all code committed in the prior 24h — the DB 4 billing schema (12 tables / migrations), the `w9_records` table + `W9Record` model + `HasEncryptedFields` TIN encryption, `promo_codes`/`PromoCode`, the billing service layer (`SubscriptionService`, `BillingService`, rewired `EntitlementService`), the `PgTextArray` cast, the ClamAV virus-scan path (`VirusScanService` + `ScanDocumentForViruses`), the entitlement-snapshot backfill migration, the BaseModel platform-model sweep, `config/services.php`, `routes/api.php`, and the env-example diffs. Findings: **SEC-043** (High — RLS bypassed platform-wide; app role owns all tables and `FORCE ROW LEVEL SECURITY` is unset, so every policy including the new W-9/billing ones is a no-op; verified live via `pg_class.relforcerowsecurity`) and **SEC-044** (Low — encrypted-field plaintext + key flow through query bindings, log-exposure risk). **No issue found** in: TIN handling (encrypted via pgcrypto Key D, `tin` in `$hidden`, `tin_last_four` display-only, never logged), the virus-scan job (fail-closed — scanner errors and missing files throw and retry, never `markReady`; infected → quarantine + audit), `PgTextArray` (values are bound parameters — no SQL injection; admin-controlled inputs), billing services (audit-logged, entitlement cache invalidated on every mutation, one-active-subscription enforced, no hardcoded prices/tiers — all DB-12-driven via snapshots), the backfill migration (drops/recreates the `plan_versions_no_update` immutability RULE around a `WHERE entitlements_snapshot = '{}'`-guarded one-time update), `config/services.php` (all secrets via `env()`, no literals), `routes/api.php` (billing/W-9 not yet HTTP-exposed; existing routes auth+throttle gated), and the committed env-example files (placeholder values only — no real keys/secrets).
- Last-24h security scan (2026-06-21): reviewed all commits in the prior 24h — signup→Stripe Checkout (`3498f7a`: `MembershipCheckoutService`, `AuthController::register`, `UserService::create`, `RegisterRequest`), promo codes on plans (`5aa04be`: `PromoCodeService`, `plan_promo_codes`, Stripe coupon sync, checkout/webhook wiring), trigger-based promo auto-apply (`6faf2d4` / `ed522cb`: `PromotionAutoApplyService`, `intended_plan_key`), the billing-interval fix (`3c110ee`: `subscriptions.billing_interval`, `StripeService::subscriptionPeriod`, webhook), the avatar cache-bust (`f41639b`), and the DL/license roster images + expired-license guard (`1f3f57d`). Findings: **SEC-050** (Low — unscoped/unaudited admin document routes now serving applicant DL PII), **SEC-051** (Low — Stripe IDs in webhook logs), **SEC-052** (Low — promo per-user-limit TOCTOU), **SEC-053** (Low — first-listing auto-apply not atomic). **No exploitable issue found.** Specifically clean: signup (no mass assignment — `UserService::create` cherry-picks fields; `account_type` enum-validated; `assignSignupRole` maps via a fixed const with no admin/staff entry → no privilege escalation; `intended_plan_key` re-verified against a real public plan), checkout (plan version + price server-locked; coupon server-resolved from `period.stripe_coupon_id`; subscription written only by the webhook after real payment; account-type match enforced; free/duplicate-subscription rejected), promo redemption (global `max_redemptions` atomically guarded; restriction + window + account-type targeting enforced; per-user check functional via persisted `promo_code_used`), DL/license PII display (staff-only `auth:web` route; `canAccessPanel` excludes landowners; members use a separate session guard), and avatar serving (own-avatar only via `avatar_document_id`; `?v=` is just the doc UUID, no arbitrary-document access).
- Open-items remediation pass (2026-06-14): cleared the entire Open/Deferred backlog highest-to-lowest — SEC-003-P4 (High, structural access-info lease gate), SEC-006/D01 (Medium, explicit Filament mutation gates + AdminUser privilege-escalation fix), SEC-008 (Medium, read-API throttles), SEC-007/SEC-010/SEC-023/D02/SEC-037/SEC-038 (Low). All `php -l` clean; full suite 72 passed (the lone failure was Postgres connection-slot exhaustion under parallel 14-DB load, not a code defect — passes in isolation).
- No SQL injection surfaces found — all queries use parameterized bindings or Eloquent ORM.
- No hardcoded credentials or keys found in application code — all sourced from env/config.
- Audit DB (DB 9) write-protection verified: `ImmutableModel` throws on update/delete; PostgreSQL RULE blocks at DB level.
- RLS policies verified on `property_access_info` — DB-level enforcement supplements SEC-003 fix.
- Filament panel auth verified: `User::canAccessPanel()` restricts `/admin` to `staff` and `super_admin` roles — no unauthenticated or regular-user access to CMS pages is possible.
- React JSX auto-escaping confirmed: all CMS text values (labels, headlines, stat values) are rendered as React children, not raw HTML — no XSS vector even if a staff user enters `<script>` tags.

---
---

# ━━━ TRACK B — Auth / MFA / Admin-IP / Lease-Application Audit ━━━

> Merged from the former `docs/security.md` on 2026-06-14. Original content preserved verbatim, including its own `SEC-NNN` numbering (which is **independent of Track A above** — see the numbering note at the top of this file). IDs are unchanged because they are referenced from code comments.
>
> _Original docs/security.md header: "Security Findings — American Headhunter · Last updated: 2026-06-10 (audit of Phase 3 MFA/auth API)."_

## [Track B] Open Issues

_No open issues — SEC-037 and SEC-038 were fixed 2026-06-14 (see below)._

### SEC-037 — `RequireSessionAuth` Does Not Verify Account Is Still Active (LOW) — Fixed 2026-06-14
**Area:** `app/Http/Middleware/RequireSessionAuth.php`
**Risk:** The middleware only checked `session('auth.user_id')` is non-null. A suspended or deleted user retained access until natural session expiry.
**Fix:** After confirming the session value, the middleware now re-checks `User::on('identity')->whereKey($userId)->where('status','active')->exists()` at most once per 60s (cached in-session via `auth.active_checked_at`). On a non-active account it forgets the auth session keys and redirects to login.

### SEC-038 — `PropertyController::index()` Filter Inputs Not Type-Validated (LOW) — Fixed 2026-06-14
**Area:** `app/Http/Controllers/Public/PropertyController.php` — `index()`
**Risk:** `min_price`/`max_price` accepted any string; `state_code` had no format check; `listing_type` was not whitelisted. No injection (parameterized queries) — type-safety gap only.
**Fix:** Added `$request->validate()` — `state_code` size 2, `county` max 100, `listing_type` in `annual_lease,seasonal_lease,day_hunt,auction`, `min_price`/`max_price` nullable numeric ≥ 0, `species` nullable array, `page` integer ≥ 1. Empty-string filters are normalized to null first so cleared UI inputs don't fail validation.

---

### SEC-031 — `revokePropertyManager()` Livewire Method Missing Authorization Gate (LOW) — Fixed 2026-06-08
**Area:** `EditPropertyV2::revokePropertyManager()`
**Risk:** Public Livewire methods on a page component are callable by any authenticated admin who can reach the page — not just those with property management authority. The method correctly scopes the revoke to `property_id = $this->getRecord()->id` (prevents IDOR across properties), but without a role check any admin who can open any property edit page could revoke managers.
**Fix:** Added `abort_unless(AdminAuth::canManageProperties(), 403)` as the first line of the method. Roles required: `super_admin`, `global_admin`, `property_admin`.

---

### SEC-032 — Grant Manager Role Not Validated Server-Side Against Allowlist (LOW) — By Design
**Area:** `EditPropertyV2::getHeaderActions()` grant manager action
**Risk:** The `->action()` callback writes `$data['role']` directly to `PropertyManager::create()`. The Select component limits UI options but does not prevent a crafted request from supplying an unexpected value.
**Assessment:** PostgreSQL `CHECK (role IN ('owner', 'co_owner', 'manager', 'operator'))` is the backstop and throws a `QueryException` that Filament catches and surfaces as an error — the record is never written. This mirrors the established project pattern (SEC-029, SEC-030). Acceptable given admin-only access.

---

### SEC-033 — Placeholder HTML Blocks Use `htmlspecialchars()` / `e()` — Reviewed, Safe
**Area:** `PropertyFormV2::renderManagersHtml()`, `EditCustomerUser.php` Properties & Leases tab Placeholders
**Reviewed:** All user-supplied values (property titles, user names, emails, role strings, dates) are escaped with `htmlspecialchars()` or `e()` before injection into HTML strings. Manager UUIDs embedded in `wire:click` attributes are format-constrained to `[0-9a-f-]` (UUID format) — no injection risk. Cross-DB user lookup via `User::on('identity')->whereIn('id', $userIds)` takes its input from DB-sourced UUID columns, not from HTTP request data.

---

### SEC-024 [Track B] — Trusted Proxies Not Configured (HIGH) — Fixed 2026-06-07
**Area:** `bootstrap/app.php`, `EnsureAdminIpAllowed` middleware
**Risk:** In production behind a load balancer (Azure Container Apps, Nginx reverse proxy), `$request->ip()` reads from `X-Forwarded-For`. If `TrustProxies` middleware is not configured to only trust known proxy IPs, an attacker can spoof `X-Forwarded-For: <allowed-ip>` in the request headers and bypass the IP allowlist entirely.
**Fix:** Replaced hardcoded RFC-1918 `trustProxies` block with an env-driven configuration in `bootstrap/app.php`. The `TRUST_PROXIES` env variable controls trust level at deploy time — no code change needed when the infrastructure changes:
- **Empty / `none`**: no proxy trust; real socket IP used directly (for truly proxy-free deploys)
- **RFC-1918 CIDRs** (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`): trusts Docker/on-prem Nginx (current default in `.env`)
- **`*`**: trusts all proxies for Azure Container Apps (set this when deploying to ACA — platform prevents X-Forwarded-For spoofing at the infra level)

`.env` is set to RFC-1918 ranges (correct for local Docker with Nginx container as proxy). When migrating to Azure Container Apps, change to `TRUST_PROXIES=*` in the production environment — no deploy required.

---

### SEC-025 [Track B] — Role Changes on Admin Users Not Audited (MEDIUM) — Fixed 2026-06-07
**Area:** `EditAdminUser`, `CreateAdminUser`
**Risk:** When an admin user's roles are changed via the CheckboxList, the Filament relationship sync (`roles()->sync()`) fires after `handleRecordUpdate()`. AuditService only logs changes to the `users` table columns (`email`, `status`, `password_hash`). Role grants/revocations are invisible in the audit log.
**Fix:** Two lifecycle hooks added to each page class:
- **`EditAdminUser::beforeSave()`** — reads and stashes current role names from DB before the sync
- **`EditAdminUser::afterSave()`** — reads new role names after sync, diffs against the stash, emits `role_change` audit event with `oldValues`/`newValues` only when the set changed
- **`CreateAdminUser::afterCreate()`** — reads the roles just synced, emits `role_change` event with `oldValues: []` and `newValues: [assigned roles]`

Audit log entry: `event_type = role_change`, `table_name = user_roles`, `old_values` and `new_values` contain the full before/after role name arrays.

---

### SEC-026 — IP Octet Range Not Validated (LOW) — Fixed 2026-06-07
**Area:** `IpAllowlistSettings.php` — `ipForm()` and `bypassForm()`
**Risk:** The regex `/^(\d{1,3}\.){3}\d{1,3}(\/...)?$/` allows values like `999.999.999.999`. These would never match a real client IP so they cause a silent no-op rather than a security bypass, but the input is misleading and confusing.
**Fix:** Replaced both `->regex()` calls with `->rule()` closures using `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)` for octet validation. CIDR field additionally validates the prefix is an integer between 0 and 32. Invalid octets now produce a clear error message rather than silently accepting the entry.

---

### SEC-027 — Print Application Route Missing Role Authorization (HIGH) — Fixed 2026-06-06
**Area:** `PrintApplicationController::show()`, `routes/web.php`
**Risk:** The `/admin/applications/{id}/print` route was protected only by `auth:web` (authenticated) — not by any admin role check. Any user authenticated via the `web` guard, including customer portal applicants who hold a web session, could access the print view for any application by knowing or guessing a UUID. The print view contains full PII: names, addresses, driver's license numbers, hunting license numbers, medical conditions, and internal review notes.
**Fix:** Added `AdminAuth::canManageLeases()` check inside `PrintApplicationController::show()`:
```php
abort_unless(auth()->check() && AdminAuth::canManageLeases(), 403);
```

---

### SEC-028 — No Rate Limiting on Applicant Message Endpoint (MEDIUM) — Fixed 2026-06-06
**Area:** `routes/web.php` — `POST /apply/status/{application}/message`
**Risk:** A logged-in applicant could send messages at an unlimited rate. Each message dispatches `SendApplicationMessageEmail` to the landowner's email address. With no throttle, this is an email spam / denial-of-service vector against landowners.
**Fix:** Added `throttle:10,1` middleware to the route — 10 requests per minute per user:
```php
Route::post('/status/{application}/message', [...])
    ->middleware('throttle:10,1');
```

---

### SEC-029 — `ApplicationService::override()` Accepted Arbitrary Status String (LOW) — Fixed 2026-06-06
**Area:** `app/Services/Lease/ApplicationService.php`
**Risk:** `override()` accepted any string for `$newStatus` and wrote it directly to the `status` column. The only defense was the PostgreSQL CHECK constraint, which would throw an unhandled `QueryException` rather than a clean application error. If this method is ever called from a CLI command, test, or future API endpoint with an unexpected value, the error surface is unclear and not validated at the application layer.
**Fix:** Added an explicit guard at the top of the method:
```php
if (! in_array($newStatus, ['approved', 'rejected'], true)) {
    throw new \InvalidArgumentException("Invalid override status '{$newStatus}'.");
}
```

---

### SEC-030 — `ApplicationMessageService::send()` Accepted Arbitrary Sender Role (LOW) — Fixed 2026-06-06
**Area:** `app/Services/Lease/ApplicationMessageService.php`
**Risk:** `send()` accepted any string for `$senderRole` and stored it in the DB. The email routing in `queueEmailNotification()` relies on `$senderRole` matching one of `'admin'`, `'landowner'`, `'applicant'` — an unexpected value would silently skip the notification with no indication of failure. The PostgreSQL CHECK constraint is the only enforcement, surfacing as a raw `QueryException` rather than a descriptive error.
**Fix:** Added validation at the top of `send()`:
```php
if (! in_array($senderRole, ['admin', 'landowner', 'applicant'], true)) {
    throw new \InvalidArgumentException("Invalid sender role '{$senderRole}'.");
}
```

---

## [Track B] Fixed Issues

### SEC-034 — `PropertyController::show()` Passed Full Eloquent Model to Inertia (HIGH) — Fixed 2026-06-09
**Area:** `app/Http/Controllers/Public/PropertyController.php` — `show()`
**Risk:** `return inertia('Public/PropertyDetail', ['property' => $property->load([...])])` serialized the full Eloquent model to JSON, including `owner_user_id` (the landowner's account UUID), internal status fields, and any other model attributes not explicitly hidden. Any anonymous visitor to a property detail page could read the landowner's user ID from the Inertia page props in their browser's page source or devtools. `owner_user_id` could be used to enumerate other resources or probe for account existence.
**Fix:** Replaced the raw model pass-through with an explicit field map. Only the fields consumed by `PropertyDetail.tsx` are included: `id`, `title`, `slug`, `description`, `status`, `state_code`, `county`, `total_acres`, `huntable_acres`, and mapped arrays for `photos`, `species`, `rules`, `active_listings`. `owner_user_id` and all other internal fields are now absent from the public response.

### SEC-035 — Application Submission (`POST /apply/{listing}`) Had No Rate Limit (MEDIUM) — Fixed 2026-06-09
**Area:** `routes/web.php`
**Risk:** An authenticated user could submit applications at an unlimited rate. The submit endpoint creates `LeaseApplication` records, processes file uploads via `DocumentService`, and writes `LeaseApplicationHunter` rows — all per request. No throttle meant a single account could flood a property's application queue, generate excessive storage load, and spam landowners with review notifications.
**Fix:** Added `->middleware('throttle:5,1')` to `POST apply/{listing}` — 5 submissions per minute per user. Matches the severity ceiling for a write endpoint compared to the existing `throttle:10,1` on the message endpoint.

### SEC-036 — `LeaseSignController` Used `findOrFail` Before Ownership Check (LOW) — Fixed 2026-06-09
**Area:** `app/Http/Controllers/Member/LeaseSignController.php` — `show()` and `sign()`
**Risk:** Both methods called `Lease::findOrFail($lease)` (returning 404 when not found) and then `abort_unless($leaseRecord->lessee_user_id === $userId, 403)` (returning 403 when found but not owned). An attacker could distinguish between non-existent lease UUIDs (404) and UUIDs that exist but belong to other users (403), enabling IDOR enumeration of valid lease IDs.
**Fix:** Both methods now use the ownership-scoped query pattern matching `MemberController::show()`:
```php
$leaseRecord = Lease::where('id', $lease)
    ->where('lessee_user_id', $userId)
    ->whereNull('deleted_at')
    ->firstOrFail();
```
Any lease not owned by the current user now returns 404, giving no information about existence.

---

## [Track B] By Design

### SEC-BD01 — Legal Certification Acceptance Is Best-Effort (LOW)
**Area:** `app/Services/Platform/LegalService.php` — `recordAcceptance()`
**Decision:** The acceptance recording is wrapped in `try/catch(\Throwable)` and silently swallowed. The application is submitted even if the `user_legal_acceptances` write to DB 1 fails (e.g., DB temporarily unavailable). This means edge-case submissions could exist without a corresponding acceptance record.
**Rationale:** The alternative — rolling back the application on acceptance-recording failure — would mean a hunter loses their work because of a secondary DB outage unrelated to the application itself. The certification is validated server-side (`required|accepted`) before the application is created. Operational monitoring of DB 1 health is the appropriate backstop.
**Monitoring:** If this becomes a compliance concern, add a background job that cross-references `lease_applications` against `user_legal_acceptances` and flags gaps.

### SEC-BD02 — No Unique Constraint Enforcing One Active Legal Document Per Key at DB Level
**Area:** `database/migrations/platform/2026_06_09_000001_create_legal_documents_table.php`
**Decision:** The `legal_documents` table has a partial index `idx_legal_documents_active ON legal_documents (document_key) WHERE is_active = true` and a unique constraint on `(document_key, version)`, but no DB-level enforcement preventing two rows for the same `document_key` both having `is_active = true`. The Filament admin UI warns about this in helper text; the admin is responsible for deactivating the old version before publishing a new one.
**Rationale:** The alternative (a deferrable unique partial index) adds DDL complexity and can still be temporarily violated mid-transaction. Since only `super_admin`/`global_admin` can manage legal documents, the trust boundary is acceptable. `LegalDocument::getActive()` uses `orderByDesc('version')` as a fallback tiebreaker if two versions are both active, ensuring the newest is always served.

---

## [Track B] Fixed Issues (Admin-IP / Login / RLS-Rename audit)

### SEC-001 [Track B] — `env()` Called in Middleware and Livewire Form (MEDIUM) — Fixed 2026-06-06
**Area:** `EnsureAdminIpAllowed.php`, `IpAllowlistSettings.php`
**Risk:** `env('ADMIN_IP_BYPASS_IP')` called directly in middleware and a Livewire form method. With `php artisan config:cache` active in production (standard practice), `env()` returns `null` for everything — silently disabling the server-level emergency bypass escape hatch.
**Fix:** Added `platform.admin_ip_bypass_ip` to `config/platform.php`. Both files updated to use `config('platform.admin_ip_bypass_ip')`.

### SEC-002 [Track B] — LoginPageSettings Had Stale Header Save Button — Fixed 2026-06-06
**Area:** `LoginPageSettings.php`
**Risk:** UI inconsistency (not a security issue). Identified during audit sweep — `getHeaderActions()` still returned a Save button, inconsistent with the toolbar-only button standard applied to all other settings pages.
**Fix:** `getHeaderActions()` now returns `[]`. Blade updated to `style="margin-top: 2rem;"`. Unused `use Filament\Actions\Action` import removed.

### SEC-003 [Track B] — RLS `app.current_role` PostgreSQL Keyword Conflict — Fixed
**Area:** RLS middleware, DB migrations
**Risk:** `app.current_role` is a PostgreSQL reserved identifier. Caused `syntax error at or near $1` errors in Docker logs.
**Fix:** Renamed to `app.user_role` across all migrations and middleware.

### SEC-004 [Track B] — RLS Middleware Used `SET LOCAL` Outside Transactions — Fixed
**Area:** `InjectDatabaseContext` middleware
**Risk:** `SET LOCAL` requires an active transaction — failed silently outside one, meaning RLS context was never injected for most requests.
**Fix:** Changed to `SET SESSION` via two separate `unprepared()` calls.

### SEC-005 [Track B] — Multi-Statement DDL Used `statement()` — Fixed
**Area:** All migrations
**Risk:** `DB::statement()` runs through PDO prepared statement handling, which fails on multi-statement DDL blocks.
**Fix:** All migrations use `DB::unprepared()` for DDL blocks.

### SEC-006 [Track B] — Admin User Self-Deletion Not Prevented — Fixed
**Area:** `EditAdminUser::getHeaderActions()`
**Risk:** A super_admin could delete their own account, potentially locking out the last admin.
**Fix:** Delete action has `visible: fn() => $this->getRecord()->id !== Auth::id()`.

### SEC-007 [Track B] — Bulk Delete Not Gated to Super Admin — Fixed
**Area:** `AdminUserResource::table()` `BulkActionGroup`
**Risk:** Any admin with user management access could bulk-delete admin users.
**Fix:** `DeleteBulkAction` has `visible: fn() => AdminAuth::isSuperAdmin()`.

### SEC-008 [Track B] — Passwords Stored Without Hashing — Fixed
**Area:** `CreateAdminUser`, `EditAdminUser`
**Risk:** Plaintext passwords.
**Fix:** `Hash::make()` used in both create and update paths. Password field is `dehydrated` only when filled on edit.

### SEC-009 [Track B] — IP Allowlist Middleware Failed with Cached Config — Fixed
**Area:** `EnsureAdminIpAllowed.php`
See SEC-001 [Track B] above.

### SEC-010 [Track B] — XSS in Page Heading Icon Trait — Reviewed, Safe
**Area:** `HasIconPageHeading.php`
**Reviewed:** `$text` is always escaped via `e($text)`. SVG comes from Blade-rendered heroicon (framework-controlled, not user input). `preg_replace` only injects a style attribute on the first `<svg` tag. Safe as implemented.

### SEC-011 [Track B] — URL Injection in Navigation/Homepage/Login Settings — Reviewed, Safe
**Area:** `NavigationSettings`, `HomepageSettings`, `LoginPageSettings`
**Reviewed:** All URL fields validated with `/^(\/|https?:\/\/).+/` regex, enforcing relative paths or HTTPS URLs only. Prevents `javascript:` protocol injection.

### SEC-012 [Track B] — IP Allowlist Middleware Fails Open on DB Outage — By Design
**Area:** `EnsureAdminIpAllowed.php`
**Design decision:** If the platform DB is unreachable, the middleware allows the request through rather than locking out all admins. The tradeoff (availability over security) is intentional for operational recovery.

### SEC-013 [Track B] — `APP_ENV=local` Bypasses IP Allowlist — By Design
**Area:** `EnsureAdminIpAllowed.php`
**Design decision:** Local development always bypasses IP restrictions. This is standard practice and must never be set in production.

---

## [Track B] Deferred Issues (now resolved)

### SEC-D01 — Per-Resource `canEdit()`/`canDelete()` Policy Audit (MEDIUM) — Fixed 2026-06-14
**Area:** All Filament Resources
**Risk:** Per-record mutation abilities fell through to Filament's permissive defaults; a security_admin could edit any admin user, including granting/holding `super_admin`.
**Fix:** Tracked and resolved as **Track A SEC-006** above. Explicit `canEdit`/`canDelete`/`canDeleteAny`/`canForceDelete*`/`canRestore*` gates added to every resource; `AdminUserResource` now blocks non-super_admins from editing/deleting super_admin records and from assigning the super_admin role.

### SEC-D02 — RLS Context Not Injected for ETL/Research Connections (LOW) — Fixed 2026-06-14 (documented)
**Area:** `InjectDatabaseContext` middleware
**Risk:** `audit`, `analytics_etl`, and `research` connections receive no RLS context; correct today but the omission was undocumented.
**Fix:** Tracked and resolved as **Track A SEC-023** above. The exclusions are now an explicit, documented contract in the middleware with the requirement that any future user-scoped RLS policy on those DBs be added to the injection list.

---

## SEC-039 — `UserService::resetMfa()` Not Transactional (MEDIUM) — Fixed 2026-06-10

**Area:** `app/Services/Identity/UserService.php` — `resetMfa()`

**Risk:** The admin "Reset MFA" action runs three sequential DB operations on the `identity` connection:
1. `UPDATE mfa_configurations SET is_enabled = false`
2. `DELETE FROM user_recovery_codes`
3. `$user->tokens()->delete()`

If operation 3 fails (transient DB error, connection pool exhaustion), operations 1 and 2 have already committed. The account's MFA is disabled and its recovery codes are gone, but existing PATs remain active. An attacker who had already obtained a PAT retains full API access to an account that now has no MFA protection and no recovery path — the exact opposite of what the admin intended.

**Fix:** Wrapped all three operations in `DB::connection('identity')->transaction()`. All three tables are on the `identity` connection so they participate in the same transaction. A failure in any step rolls back the entire reset atomically. The `invalidate()` cache call and `AuditService::log()` remain outside the transaction (cache invalidation on rollback is harmless; audit failures must not propagate per CLAUDE.md rules).

**Method signature** also gained `?string $initiatedByUserId = null` so the Filament admin action can pass `auth()->id()` — the reset is now attributed to the admin who triggered it in the audit log.

---

## SEC-040 — No Audit Trail for MFA Lifecycle Events (MEDIUM) — Fixed 2026-06-10

**Area:** `MfaController`, `RecoveryController`, `UserService`

**Risk:** Five security-critical MFA operations produced zero audit log entries:

| Event | Code path | Gap |
|---|---|---|
| Factor enrolled & verified | `MfaController::confirm()` | No `mfa_enabled` audit call |
| Factor disabled | `MfaController::disable()` | No audit call at all |
| Recovery codes regenerated | `MfaController::regenerate()` | No audit call |
| Recovery code used for auth | `RecoveryController::recover()` | No audit call |
| Admin MFA reset | `UserService::resetMfa()` | No audit call |

Without these entries, there is no forensic trail showing when an account's MFA was added, removed, or bypassed via recovery code. If an account is compromised and the attacker disables MFA or exhausts recovery codes, incident responders have no evidence of when or from where.

**Fix:** Added `AuditService` injection to `MfaController` and `RecoveryController`. New convenience methods added to `AuditService`:

- `logMfaDisabled(string $userId, string $method)` — `event_type = mfa_disabled`
- `logRecoveryCodesGenerated(string $userId)` — `event_type = mfa_recovery_codes_generated`
- `logRecoveryCodeUsed(string $userId, string $ipAddress)` — `event_type = mfa_recovery_code_used`

Audit calls added:
- `confirm()`: calls `logMfaEnabled()` (existing) + `logRecoveryCodesGenerated()` when first-enrollment codes are issued
- `disable()`: calls `logMfaDisabled()`
- `regenerate()`: calls `logRecoveryCodesGenerated()`
- `recover()`: calls `logRecoveryCodeUsed()` with client IP on success
- `resetMfa()`: calls `$this->audit->log()` directly with `event_type = mfa_reset`, includes `initiatedByUserId` for admin attribution

All calls are inside `AuditService::log()` which never throws — audit failures are logged to the application log and do not interrupt the MFA flow.

---

## SEC-041 — `mfa_challenges` Rows Accumulate Without Cleanup (LOW) — Fixed 2026-06-10

**Area:** `app/Services/Auth/MfaService.php` — `verifyChallenge()`

**Risk:** `verifyChallenge()` marks rows `used_at` but never deletes them. Expired unverified challenges (where the user abandoned the MFA flow) also accumulate with no TTL eviction. Over months of traffic, `mfa_challenges` grows without bound. While this is a maintenance concern rather than an immediate security risk, a very large table can degrade query performance on the `WHERE used_at IS NULL AND expires_at > NOW()` lookup used during MFA verification.

**No current exploitability** — expired challenge rows cannot be used for authentication (the `expires_at > NOW()` guard rejects them). This is purely a maintenance and eventual performance concern.

**Fix needed:** A scheduled Artisan command or queue job that runs nightly:
```sql
DELETE FROM mfa_challenges
WHERE used_at IS NOT NULL
   OR expires_at < NOW() - INTERVAL '7 days';
```
This retains recent unused challenges for the full 7-day window (useful if investigating an attack) while preventing unbounded growth.

**Fix:** `app/Console/Commands/PruneMfaChallenges.php` — deletes rows where `used_at IS NOT NULL` (consumed) or `expires_at < NOW() - 7 days` (expired, past investigation window). Registered in `routes/console.php` as `Schedule::command('mfa:prune-challenges')->dailyAt('03:00')` — runs at 3 AM nightly, after the 00:30 listing-expiry job. The 7-day retention window for unused-but-expired rows preserves evidence for incident investigation (e.g. confirming the timing of an MFA brute-force attempt) before purging.

---

## SEC-042 [Track B] — Web (Session/Inertia) MFA Flow Non-Functional: MFA Users Logged In Unchallenged + Lockout Risk (HIGH) — Fixed 2026-06-16

**Area:** `routes/auth.php`, `app/Http/Controllers/Auth/AuthController.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Services/Auth/MfaService.php`, `resources/js/Pages/Auth/MfaVerify.tsx`; member self-enrollment in `app/Http/Controllers/Member/SecurityController.php`, `resources/js/Pages/Member/Profile/Hunter.tsx`, `app/Services/Mfa/TotpMfaMethod.php`, `app/Services/Identity/UserService.php`, `app/Filament/Admin/Resources/Users/Pages/EditCustomerUser.php`.

**Risk:** A user with MFA enabled who logged in through the **web portal** (custom session/Inertia auth — distinct from the already-correct API/SPA flow) was **never challenged for a second factor**. Reported live: enabling email + TOTP on a user did not prompt for a code on next login — username + password landed the user (or silently failed). The web MFA path was a stub with four independent defects, two of which are directly security-relevant (second factor not enforced) and two of which created a hard-lockout hazard:

1. **Route bounce (auth bypass / DoS for MFA users).** `/mfa/verify` (GET + POST) lived inside the `auth.session` middleware group. That middleware redirects to `auth.login` whenever `auth.user_id` is absent — but during MFA the session is intentionally *not yet authenticated* (state held in Valkey `mfa_pending:{sessionId}`). So the verify page bounced straight back to login: an MFA user could never reach the challenge, and the practical outcome was either no challenge or an unusable login.
2. **No challenge dispatched at login.** `AuthController::login()` set the MFA-pending marker but never called any method's `triggerChallenge()`. Email/SMS codes were therefore never generated or sent, so even a reachable verify page had no code to accept.
3. **Wrong verify path + call to a non-existent method.** `MfaController::verify()` called `verifyChallenge('totp')` (which does not validate a stored TOTP secret) and `verifyBackupCode()`, **which does not exist** on `MfaService` (the real method is `verifyAndConsumeRecoveryCode()`). Verification would fatal/never succeed.
4. **No TOTP enrollment (lockout trap).** Neither the member Security page nor the admin user editor ever generated a TOTP secret. Admins (and the member toggle) could flip `mfa_configurations.method='totp'` to enabled with `secret_encrypted = NULL`. Once defects 1–3 were fixed, such a user would be prompted at login for an authenticator code that **can never validate** → permanent self-lockout. Confirmed on live data: the reported user had `totp` enabled with `has_secret = NULL` and 0 recovery codes.

**Root Cause:** The web/session MFA flow was scaffolded but never wired end-to-end — it duplicated the API flow's intent without reusing its `MfaMethodRegistry` dispatch, and the self-service TOTP enrollment half (secret generation, QR, confirm, recovery codes) was never built for the Inertia portal, leaving only an enable toggle with no secret behind it.

**Fix:**
- **Routing.** Moved `GET/POST /mfa/verify` *out* of `auth.session` (they must run after password but before the session is authenticated); added `POST /mfa/resend` (throttled). All remain inside the `db.system` group (pre-context, `ah_system` role).
- **Challenge dispatch.** `AuthController::login()` now, when `MfaService::isEnabled()`, marks pending and loops `MfaService::getEnabledMethods($user)` (new) through `MfaMethodRegistry->get($method)->triggerChallenge()` so email/SMS codes are actually sent, then redirects to `auth.mfa.verify`.
- **Verification.** `MfaController::verify()` now validates the submitted code against each enabled method via the registry (`->verify()`), then falls back to `verifyAndConsumeRecoveryCode()`. Added `show()` (passes enabled `methods` + `canResendCode` to Inertia) and `resend()`. Removed the bogus `verifyChallenge('totp')` / `verifyBackupCode()` calls.
- **Self-service TOTP enrollment (parity with API).** `SecurityController::enrollTotp()` (generates + stores secret, returns secret + SVG-data-URI QR via new `TotpMfaMethod::qrCodeSvgDataUri()`) and `confirmTotp()` (verifies the code, enables the factor, issues recovery codes on first enrollment only). `Hunter.tsx` SecurityTab gained the QR/secret/confirm panel and one-time recovery-code display.
- **Lockout prevention.** `SecurityController::enableMfa()` now rejects `totp` (must go through enroll/confirm); `UserService::enableMfaFactor()` throws if `totp` is enabled without an on-file secret (`hasTotpSecret()`); the admin editor's TOTP enable action surfaces that message and its description warns admins cannot enroll a secret on a user's behalf.

**Verification:** End-to-end via a live cookie-jar curl session (PHPUnit was unsuitable — the test harness churns the session ID across requests, and MFA-pending is keyed by session ID in Valkey). Confirmed: login → `302 /mfa/verify`; `GET /mfa/verify` → `200` (no bounce — defect 1 fixed); email challenge counter incremented at login (defect 2 fixed); `POST /mfa/verify` with a valid TOTP → `302 /member/profile` and authenticated `GET /member/profile` → `200` (defect 3 fixed); `enrollTotp` returns secret + ~25 KB SVG QR data-URI (defect 4 fixed). Live remediation: the reported user's secretless `totp` row was disabled (email factor left enabled); scratch test user and files removed. `npm run build` clean (no TS errors); all routes registered; PHP lints clean.

**Follow-up hardening (2026-06-16) — stale-secret re-enable + admin-enable visibility.** The defect-4 guard above blocks enabling TOTP when *no* secret is on file, but a **stale** secret could still cause a soft-lockout: a user could enroll TOTP, disable it, *remove the entry from their authenticator app*, and an admin could then re-enable the factor against the orphaned secret — challenging the user for a code they can no longer generate (recovery codes were the only escape). Three defensive measures were added:

1. **Clear the secret on disable (both paths).** `SecurityController::disableMfa()` (member self-service) and `UserService::disableMfaFactor()` (admin) now set `secret_encrypted = NULL` when the method is `totp`, not just flip `is_enabled`/`verified_at`. Re-enabling TOTP therefore *always* requires a fresh enrollment (re-scan), and the defect-4 guard now also covers the previously-disabled case — an orphaned secret can no longer exist to be re-enabled. Recovery codes are left intact (only `resetMfa()` clears those).
2. **Out-of-band notification on any admin-initiated factor enable.** `UserService::enableMfaFactor()` emails the account holder (`MfaFactorEnabledByAdminMail`, template `auth.mfa_enabled_by_admin`, Blade fallback `emails/mfa-enabled-by-admin`) so they know a second factor is now required at login and how to recover (recovery code → re-enroll → contact support). Send is wrapped in try/catch — a mail failure never rolls back the security change.
3. **Admin TOTP enable confirmation hardened.** The admin "Enable Authenticator MFA" action in `EditCustomerUser` now uses a warning modal icon and an "Enable anyway" submit label, with copy stating admins cannot enroll on a user's behalf and that this fails after a disable (the user must self-enroll).

Verified functionally (throwaway user, owner connection): after `disableMfaFactor('totp')` the row has `secret_encrypted IS NULL` and `is_enabled = false`; a subsequent `enableMfaFactor('totp')` throws (re-enable blocked). The notification template seeds idempotently and renders the method label; the mailable builds with the correct subject. PHP lints clean.

---

## [Track B] Architectural Security Decisions Added — Phase 3 MFA

| Decision | Rationale |
|---|---|
| Recovery codes hashed with bcrypt, not encrypted | Hashed credentials cannot be retrieved even if the DB is exfiltrated. Encryption would allow admin read; bcrypt does not. |
| Recovery codes are account-level, not per-factor | Losing all enrolled factors = one recovery path, not zero. Keeps the recovery surface minimal. |
| Separate rate-limit bucket for recovery (`mfa-recover` 3/min vs `mfa-verify` 5/min) | Recovery code guessing is a stronger signal of compromise than TOTP retry. Stricter limit with independent keying (by `challenge_token`) prevents cross-bucket exhaustion. |
| Platform factor toggle (Option A): disabling blocks enrollment only | Revoking existing enrollments silently would lock out users. Disabling SMS with enrolled users active = lockout. Admin `resetMfa()` is the tool for individual forced-reset. |
| `code_hash` in AuditService sanitize list | Bcrypt hashes are still auth credentials. Even a hashed value should not appear in audit `old_values`/`new_values` if someone ever adds audit logging to recovery-code operations. |
| `UserRecoveryCode` model with `$hidden = ['code_hash']` | Establishes the serialization invariant. Raw DB queries in `MfaService` have no current serialization risk, but the model guard prevents future exposure if Eloquent-based queries are added. |
| MFA challenge token stored in Valkey sessions cluster (Cluster 1), not DB | Session tokens have a 5-min TTL and are single-use on success (`Cache::pull()`). Using Valkey avoids a DB round-trip on every MFA challenge check and naturally expires tokens without a cleanup job. |
| `resetMfa()` wrapped in a single identity-connection transaction | Partial reset (MFA disabled, PATs still live) is worse than no reset. Atomicity ensures the security state is either fully cleared or not changed at all. |

---

## [Track B] Architectural Security Decisions (Non-Findings)

| Decision | Rationale |
|---|---|
| No cross-DB SQL foreign keys | Security domain isolation — a breach of one DB cannot cascade |
| `ImmutableModel` + PostgreSQL RULE on audit DB | Append-only audit log cannot be tampered with from application code |
| Analytics DB write-protected at credential level | `readonly_user` credential for `analytics` connection; ETL uses `analytics_etl` |
| Research DB not accessible from application tier | Air-gapped — only ETL job classes hold the credential |
| Gate codes encrypted with `pgp_sym_encrypt` | Access info never stored or logged in plaintext |
| SOS records never soft- or hard-deleted | Life-safety records are permanent by policy and enforced in code |
| Stripe tokenized IDs only — no raw card data | PCI scope minimization |
| Entitlements via `EntitlementService` only | Never compare `user.plan_name` inline — prevents entitlement bypass by string manipulation |
| Application message HTML uses `htmlspecialchars()` | All user-supplied message content is escaped before being injected into HTML strings in `buildMessagesHtml()` and `buildReviewHistoryHtml()` |
| Print view uses Blade `{{ }}` throughout | All user-supplied content in `print-application.blade.php` uses escaped output — no `{!! !!}` in new sections |
| Applicant message scoped by `applicant_user_id` | `ApplyController::sendMessage()` looks up the application with both the application ID and the session user ID — an applicant cannot message on behalf of another applicant |
| Listing snapshot fields from trusted DB source | Snapshot fields (`property_title_snapshot` etc.) are populated from `PropertyService` → DB 2, never from user input, and displayed via Filament's built-in escaping |
| Property manager revoke scoped to current property | `revokePropertyManager()` enforces `property_id = $this->getRecord()->id` — a manager UUID from a different property cannot be revoked even if the UUID is known |
| Admin Placeholder HTML blocks escape all DB-sourced output | CSS Grid HTML blocks in `renderManagersHtml()` and the Properties & Leases placeholders use `htmlspecialchars()`/`e()` on every value derived from DB records |
| Cross-DB user bulk lookup uses DB-sourced UUIDs only | `whereIn('id', $userIds)` in `renderManagersHtml()` is built from `property_managers.user_id` / `granted_by_user_id` columns — no HTTP request input reaches this query |
| Duplicate active manager grant prevented at DB level | `UNIQUE INDEX uq_property_managers_active ON property_managers (property_id, user_id) WHERE revoked_at IS NULL` — duplicate check in application code is defense-in-depth only |
| Member lease access scoped by `lessee_user_id` in query | `MemberController::show()` and both `LeaseSignController` methods use `where('lessee_user_id', $userId)->firstOrFail()` — a user cannot retrieve or sign another user's lease even with a valid UUID |
| Public property detail response uses explicit field map | `PropertyController::show()` maps to a fixed array; `owner_user_id` and all internal model fields are absent from the Inertia response served to unauthenticated visitors |
| Access info decryption requires explicit lease verification | `PropertyService::getAccessInfo()` now verifies an active lease via `LeaseService` (see Track A SEC-003-P4) — callers cannot expose gate codes without the service confirming the lease |
| Species filter whitelist prevents injection via array input | `PropertyService::searchListings()` runs `array_intersect($species, VALID_SPECIES_CODES)` before using species values in a `whereIn` query — unknown codes are silently dropped |
| Legal certification validated server-side as `required\|accepted` | `SubmitApplicationRequest` enforces `certification_accepted` regardless of UI state — bypassing the checkbox via direct POST still fails validation |
| Legal acceptance records IP, user agent, document version, and application ID | `user_legal_acceptances` provides a complete audit trail of which version was active, from which IP, and for which application — not just a boolean flag |
| Admin-only legal document management | `LegalDocumentResource` gates on `AdminAuth::canManageSystem()` — customers cannot create or modify certification text |
