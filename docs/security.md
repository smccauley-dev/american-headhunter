# Security Findings — American Headhunter

Tracks all security findings identified during code audits. Each finding has a status: **Fixed**, **Open**, **Deferred**, or **By Design**.

Last updated: 2026-06-10 (audit of Phase 3 MFA/auth API — all new code from hunter-loop API, MFA enrollment/recovery, admin MFA panel)

---

## Open Issues

### SEC-037 — `RequireSessionAuth` Does Not Verify Account Is Still Active (LOW)
**Area:** `app/Http/Middleware/RequireSessionAuth.php`
**Risk:** The middleware only checks `session('auth.user_id')` is non-null. A user whose account has been suspended or deleted retains full access until their session token expires (default Laravel session TTL). If an admin bans a user, they remain on the platform until natural session expiry.
**Fix needed:** After confirming the session value exists, do a lightweight DB check: `User::on('identity')->where('id', $userId)->where('status', 'active')->exists()`. Cache the result in the session for ~60 seconds to avoid per-request DB hits.
**Status:** Open — no blocking urgency; acceptable for current scale. Add during auth hardening pass.

### SEC-038 — `PropertyController::index()` Filter Inputs Not Type-Validated (LOW)
**Area:** `app/Http/Controllers/Public/PropertyController.php` — `index()`
**Risk:** `min_price`, `max_price` accept any string and are passed directly to parameterized DB queries. Non-numeric values produce no error and return 0/unexpected results. `state_code` has no length or format check; `listing_type` is not whitelisted. SQL injection is fully prevented by parameterized queries — this is a type-safety / unexpected-behavior gap only.
**Fix needed:** Add a `Request::validate()` call: `state_code` max 2 chars, `listing_type` in known set, `min_price`/`max_price` nullable numeric.
**Status:** Open — low impact, no injection risk. Fix during next controller cleanup pass.

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

### SEC-024 — Trusted Proxies Not Configured (HIGH) — Fixed 2026-06-07
**Area:** `bootstrap/app.php`, `EnsureAdminIpAllowed` middleware  
**Risk:** In production behind a load balancer (Azure Container Apps, Nginx reverse proxy), `$request->ip()` reads from `X-Forwarded-For`. If `TrustProxies` middleware is not configured to only trust known proxy IPs, an attacker can spoof `X-Forwarded-For: <allowed-ip>` in the request headers and bypass the IP allowlist entirely.  
**Fix:** Replaced hardcoded RFC-1918 `trustProxies` block with an env-driven configuration in `bootstrap/app.php`. The `TRUST_PROXIES` env variable controls trust level at deploy time — no code change needed when the infrastructure changes:
- **Empty / `none`**: no proxy trust; real socket IP used directly (for truly proxy-free deploys)
- **RFC-1918 CIDRs** (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`): trusts Docker/on-prem Nginx (current default in `.env`)
- **`*`**: trusts all proxies for Azure Container Apps (set this when deploying to ACA — platform prevents X-Forwarded-For spoofing at the infra level)

`.env` is set to RFC-1918 ranges (correct for local Docker with Nginx container as proxy). When migrating to Azure Container Apps, change to `TRUST_PROXIES=*` in the production environment — no deploy required.

---

### SEC-025 — Role Changes on Admin Users Not Audited (MEDIUM) — Fixed 2026-06-07
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

## Fixed Issues

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

## By Design

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

## Fixed Issues

### SEC-001 — `env()` Called in Middleware and Livewire Form (MEDIUM) — Fixed 2026-06-06
**Area:** `EnsureAdminIpAllowed.php`, `IpAllowlistSettings.php`  
**Risk:** `env('ADMIN_IP_BYPASS_IP')` called directly in middleware and a Livewire form method. With `php artisan config:cache` active in production (standard practice), `env()` returns `null` for everything — silently disabling the server-level emergency bypass escape hatch.  
**Fix:** Added `platform.admin_ip_bypass_ip` to `config/platform.php`. Both files updated to use `config('platform.admin_ip_bypass_ip')`.

### SEC-002 — LoginPageSettings Had Stale Header Save Button — Fixed 2026-06-06
**Area:** `LoginPageSettings.php`  
**Risk:** UI inconsistency (not a security issue). Identified during audit sweep — `getHeaderActions()` still returned a Save button, inconsistent with the toolbar-only button standard applied to all other settings pages.  
**Fix:** `getHeaderActions()` now returns `[]`. Blade updated to `style="margin-top: 2rem;"`. Unused `use Filament\Actions\Action` import removed.

### SEC-003 — RLS `app.current_role` PostgreSQL Keyword Conflict — Fixed
**Area:** RLS middleware, DB migrations  
**Risk:** `app.current_role` is a PostgreSQL reserved identifier. Caused `syntax error at or near $1` errors in Docker logs.  
**Fix:** Renamed to `app.user_role` across all migrations and middleware.

### SEC-004 — RLS Middleware Used `SET LOCAL` Outside Transactions — Fixed
**Area:** `InjectDatabaseContext` middleware  
**Risk:** `SET LOCAL` requires an active transaction — failed silently outside one, meaning RLS context was never injected for most requests.  
**Fix:** Changed to `SET SESSION` via two separate `unprepared()` calls.

### SEC-005 — Multi-Statement DDL Used `statement()` — Fixed
**Area:** All migrations  
**Risk:** `DB::statement()` runs through PDO prepared statement handling, which fails on multi-statement DDL blocks.  
**Fix:** All migrations use `DB::unprepared()` for DDL blocks.

### SEC-006 — Admin User Self-Deletion Not Prevented — Fixed
**Area:** `EditAdminUser::getHeaderActions()`  
**Risk:** A super_admin could delete their own account, potentially locking out the last admin.  
**Fix:** Delete action has `visible: fn() => $this->getRecord()->id !== Auth::id()`.

### SEC-007 — Bulk Delete Not Gated to Super Admin — Fixed
**Area:** `AdminUserResource::table()` `BulkActionGroup`  
**Risk:** Any admin with user management access could bulk-delete admin users.  
**Fix:** `DeleteBulkAction` has `visible: fn() => AdminAuth::isSuperAdmin()`.

### SEC-008 — Passwords Stored Without Hashing — Fixed
**Area:** `CreateAdminUser`, `EditAdminUser`  
**Risk:** Plaintext passwords.  
**Fix:** `Hash::make()` used in both create and update paths. Password field is `dehydrated` only when filled on edit.

### SEC-009 — IP Allowlist Middleware Failed with Cached Config — Fixed
**Area:** `EnsureAdminIpAllowed.php`  
See SEC-001 above.

### SEC-010 — XSS in Page Heading Icon Trait — Reviewed, Safe
**Area:** `HasIconPageHeading.php`  
**Reviewed:** `$text` is always escaped via `e($text)`. SVG comes from Blade-rendered heroicon (framework-controlled, not user input). `preg_replace` only injects a style attribute on the first `<svg` tag. Safe as implemented.

### SEC-011 — URL Injection in Navigation/Homepage/Login Settings — Reviewed, Safe
**Area:** `NavigationSettings`, `HomepageSettings`, `LoginPageSettings`  
**Reviewed:** All URL fields validated with `/^(\/|https?:\/\/).+/` regex, enforcing relative paths or HTTPS URLs only. Prevents `javascript:` protocol injection.

### SEC-012 — IP Allowlist Middleware Fails Open on DB Outage — By Design
**Area:** `EnsureAdminIpAllowed.php`  
**Design decision:** If the platform DB is unreachable, the middleware allows the request through rather than locking out all admins. The tradeoff (availability over security) is intentional for operational recovery.

### SEC-013 — `APP_ENV=local` Bypasses IP Allowlist — By Design
**Area:** `EnsureAdminIpAllowed.php`  
**Design decision:** Local development always bypasses IP restrictions. This is standard practice and must never be set in production.

---

## Deferred Issues

### SEC-D01 — Per-Resource `canEdit()`/`canDelete()` Policy Audit (MEDIUM)
**Area:** All Filament Resources  
**Risk:** `canAccess()` gates page-level access, but individual record actions (`EditAction`, `DeleteAction`) are not consistently gated with per-record authorization checks beyond what `canAccess()` provides. A security_admin could theoretically edit any admin user record, not just those below their privilege level.  
**Status:** Deferred to Phase 4 (full policy layer).

### SEC-D02 — RLS Context Not Injected for ETL/Research Connections (LOW)
**Area:** `InjectDatabaseContext` middleware  
**Risk:** The RLS middleware only injects `app.user_id` and `app.user_role` for the primary application connections. The `audit`, `analytics_etl`, and `research` connections are not injected. Since ETL jobs connect directly (not through HTTP middleware), this is expected but undocumented.  
**Status:** Deferred — acceptable since ETL connections are not exposed through the HTTP layer.

---


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

## Architectural Security Decisions Added — Phase 3 MFA

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

## Architectural Security Decisions (Non-Findings)

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
| Access info decryption requires explicit lease verification flag | `PropertyService::getAccessInfo()` throws if `$callerHasVerifiedLease = false` (the default) — callers cannot accidentally expose gate codes without asserting they verified the lease |
| Species filter whitelist prevents injection via array input | `PropertyService::searchListings()` runs `array_intersect($species, VALID_SPECIES_CODES)` before using species values in a `whereIn` query — unknown codes are silently dropped |
| Legal certification validated server-side as `required\|accepted` | `SubmitApplicationRequest` enforces `certification_accepted` regardless of UI state — bypassing the checkbox via direct POST still fails validation |
| Legal acceptance records IP, user agent, document version, and application ID | `user_legal_acceptances` provides a complete audit trail of which version was active, from which IP, and for which application — not just a boolean flag |
| Admin-only legal document management | `LegalDocumentResource` gates on `AdminAuth::canManageSystem()` — customers cannot create or modify certification text |
