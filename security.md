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

## Open / Deferred Items

| ID | Description | Severity | Status | Target Phase |
|---|---|---|---|---|
| _None_ | All previously tracked open/deferred items in this file were remediated on 2026-06-14. | — | — | — |

---

## Auditing Notes

- Phase 3 initial audit: all issues in the property domain (DB 2 / `PropertyService`).
- Phase 3 CMS audit (2026-05-25): `HomepageSettings` and `NavigationSettings` Filament pages reviewed. Findings: SEC-011 (URL injection), SEC-012 (no audit trail), SEC-013 (no maxLength). All fixed same session.
- Phase 3 logo upload audit (2026-05-25): File upload feature reviewed. Findings: SEC-014 (SVG XSS via direct URL), SEC-015 (orphaned public files), SEC-016 (untrusted path in Storage::url). All fixed same session.
- Phase 3/4 admin UI audit (2026-05-31): Property V2 edit/view pages, amenities resource, login page settings, RLS middleware reviewed. Findings: SEC-017 through SEC-022. All fixed same session. SEC-023 deferred (no RLS on those DBs yet).
- Last-24h feature audit (2026-06-13): reviewed the DB-managed email template system (`EmailTemplateService`, `MailSettingsService`, `EmailSettings`, version preview), the property-map feature (`PropertyMapService`, `ExifGps`, map editor, public map route), the configurable post-login redirect, and the new encrypted UserProfile contact fields. Findings: SEC-024 (EXIF GPS published by default) and SEC-025 (map route ignored property status) — both fixed same session. **No issue found** in: email template rendering (variables HTML-escaped in HTML bodies via `htmlspecialchars`; admin-only authorship; preview iframe uses `sandbox=""`), SMTP settings (password encrypted at rest via `Crypt`, never echoed to the form, change audit-logged, access gated by `canManageSystem`), or the post-login redirect (value comes from admin-controlled tenant settings, not user input).
- Last-24h feature audit (2026-06-14): reviewed the member field-check-in + QR system (`CheckInController`, `CheckInService`), the stand-map / boundary overlay (`PropertyMapService::getBoundaryOverlay`, `MemberController`), the property contact directory (`PropertyService::getContactDirectory`, admin Contacts tab, `Api/PropertyContactController`), the opt-in manager-contact flow (`is_field_contact` migration + `PropertyManager` model + `EditPropertyV2::removeManagerContact`), and the executed-lease PDF download (`LeaseSignController`, `EsignatureService::downloadSignedLease`). Finding: SEC-042 (`manager_id` UUID disclosed to lessees) — fixed same session. **No issue found** in: check-in (`checkIn`/`checkOut` enforce `abort_unless(mayCheckIn, 403)` — lessee or approved LeaseHunter only; check-out scoped to the user's own open record), stand-map markers + access info (served only for the lessee's own `active` lease, GPS member-only per SEC-024), contact directory (gated by `userHasActiveLeaseForProperty`, returns 404 not 403 to non-lessees per SEC-024; intended landowner/manager contact details are by design), the opt-in manager flow (admin-only writes via `AdminAuth::canManageProperties`; managers no longer auto-listed to hunters), lease PDF download (`abort_if(403)` unless lessee or lessor; download audit-logged via AuditService), and the PDF/QR/check-in-log blades (all output escaped — no `{!! !!}`). **Out-of-window note:** the admin `auth:web` document download/view routes (`/admin/documents/{documentId}/download`, `/view`) do a bare `findOrFail` + `Storage::download` with no per-document ownership check. These predate this window (commit `05df4a5`) and are reachable only through the `web` (admin) guard — members authenticate via the separate `auth.session`/`RequireSessionAuth` system — so they are effectively admin-restricted, but lack defense-in-depth ownership scoping. Logged here; not assigned a SEC ID this pass.
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
