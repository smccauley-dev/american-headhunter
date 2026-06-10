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

## Open / Deferred Items

| ID | Description | Severity | Status | Target Phase |
|---|---|---|---|---|
| SEC-003-P4 | Replace `$callerHasVerifiedLease` bool flag in `getAccessInfo` with a real `LeaseService::hasActiveLease()` call so the check cannot be bypassed | High | DEFERRED | Phase 4 |
| SEC-006 | Full per-resource `canAccess()` / `canEdit()` / `canDelete()` / `canForceDelete()` policy audit across all Filament resources. Panel-level auth (`staff`/`super_admin` only) is the current baseline; resource-level row guards are Phase 4 work. SEC-019 addressed `ForceDeleteAction` on property pages. | Medium | OPEN | Phase 4 |
| SEC-007 | `setAccessInfo()` has no rate limiting — a staff user could overwrite gate codes in rapid succession without an audit trail throttle | Low | OPEN | Phase 4 |
| SEC-008 | API rate limiting — `/api/properties` and `/api/properties/{id}` have no per-IP throttle applied. Laravel's `throttle:api` middleware is not yet attached to these routes | Medium | OPEN | Phase 3 close-out |
| SEC-010 | Audit all other `_read` DB connections (wildlife_read, geospatial_read) to ensure `ah_readonly` grants exist before building those features | Low | OPEN | Per-phase as each DB is activated |
| SEC-023 | RLS middleware (`InjectDatabaseContext`) does not inject context for `audit`, `analytics_etl`, and `research` connections. These DBs have no user-scoped RLS policies today, but if added in future phases, the middleware must be updated to include them. | Low | OPEN | Per-phase as RLS is added to those DBs |

---

## Auditing Notes

- Phase 3 initial audit: all issues in the property domain (DB 2 / `PropertyService`).
- Phase 3 CMS audit (2026-05-25): `HomepageSettings` and `NavigationSettings` Filament pages reviewed. Findings: SEC-011 (URL injection), SEC-012 (no audit trail), SEC-013 (no maxLength). All fixed same session.
- Phase 3 logo upload audit (2026-05-25): File upload feature reviewed. Findings: SEC-014 (SVG XSS via direct URL), SEC-015 (orphaned public files), SEC-016 (untrusted path in Storage::url). All fixed same session.
- Phase 3/4 admin UI audit (2026-05-31): Property V2 edit/view pages, amenities resource, login page settings, RLS middleware reviewed. Findings: SEC-017 through SEC-022. All fixed same session. SEC-023 deferred (no RLS on those DBs yet).
- No SQL injection surfaces found — all queries use parameterized bindings or Eloquent ORM.
- No hardcoded credentials or keys found in application code — all sourced from env/config.
- Audit DB (DB 9) write-protection verified: `ImmutableModel` throws on update/delete; PostgreSQL RULE blocks at DB level.
- RLS policies verified on `property_access_info` — DB-level enforcement supplements SEC-003 fix.
- Filament panel auth verified: `User::canAccessPanel()` restricts `/admin` to `staff` and `super_admin` roles — no unauthenticated or regular-user access to CMS pages is possible.
- React JSX auto-escaping confirmed: all CMS text values (labels, headlines, stat values) are rendered as React children, not raw HTML — no XSS vector even if a staff user enters `<script>` tags.
