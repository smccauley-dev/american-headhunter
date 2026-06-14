# American Headhunter — Security Issue Tracker

This file tracks all identified security issues: their severity, root cause, fix applied, and verification status.

**Status legend:** `OPEN` · `FIXED` · `VERIFIED` · `DEFERRED` · `WONT-FIX`

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
