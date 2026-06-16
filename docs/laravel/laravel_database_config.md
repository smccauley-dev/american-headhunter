# Laravel Database Configuration

This document is the authoritative reference for how database and cache connections are configured in `config/database.php`, `config/cache.php`, `config/queue.php`, and `config/session.php`. Read this before writing any code that touches a database connection, Valkey, sessions, or the job queue.

---

## Architecture Overview

The platform uses **14 PostgreSQL databases** on a single PostgreSQL 16 + PostGIS server, and **5 Valkey clusters** (one container each). All 14 databases live in the same PostgreSQL instance in dev and are separated by credential in production. No cross-database SQL joins exist anywhere — all cross-database data assembly happens in the Laravel service layer.

---

## Database Connections

`config/database.php` defines 18 named connections. The default is `identity` — but **never rely on the default**. Every model, migration, seeder, and raw query must specify its connection explicitly.

### Connection Map

| Connection key | Database name | DB # | Purpose | User |
|---|---|---|---|---|
| `identity` | `ah_identity` | 1 | Auth, users, roles, MFA, trust scores | `ah_runtime` |
| `property` | `ah_property` | 2 | Property listings, photos, pricing | `ah_runtime` |
| `property_read` | `ah_property` | 2 | Read replica — public listing queries | `ah_readonly` |
| `lease` | `ah_lease` | 3 | Leases, applications, clubs, check-in | `ah_runtime` |
| `billing` | `ah_billing` | 4 | Payments, invoices, Stripe, payouts | `ah_runtime` |
| `wildlife` | `ah_wildlife` | 5 | Harvest logs, trail cameras, quotas | `ah_runtime` |
| `wildlife_read` | `ah_wildlife` | 5 | Read replica — reporting queries | `ah_readonly` |
| `commerce` | `ah_commerce` | 6 | Auctions, marketplace, outfitter bookings | `ah_runtime` |
| `communications` | `ah_communications` | 7 | Messages, notifications, SOS events | `ah_runtime` |
| `analytics` | `ah_analytics` | 8 | Read-only reporting (ETL-populated) | `ah_readonly` |
| `analytics_etl` | `ah_analytics` | 8 | ETL writer — only ETL job classes use this | `ah_etl` |
| `audit` | `ah_audit` | 9 | Append-only audit log — 10yr retention | `ah_runtime` |
| `incidents` | `ah_incidents` | 10 | Safety incidents, disputes, moderation | `ah_runtime` |
| `documents` | `ah_documents` | 11 | File metadata, e-sign requests, QR codes | `ah_runtime` |
| `platform` | `ah_platform` | 12 | Feature flags, tenant config, IoT, promos | `ah_runtime` |
| `geospatial` | `ah_geospatial` | 13 | PostGIS: boundaries, zones, harvest locations | `ah_runtime` |
| `geospatial_read` | `ah_geospatial` | 13 | Read replica — Mapbox tile queries, spatial reads | `ah_readonly` |
| `research` | `ah_research` | 14 | Air-gapped anonymized dataset — ETL only | `ah_etl` |

### Database Users

Users are provisioned by `docker/postgres/init.sql`:

| User | Purpose | Who uses it |
|---|---|---|
| `ah_app` | Schema **owner** (DDL). Bypasses RLS as owner — **never a runtime connection** | Migrations & seeders only (`migrate:*`, `db:seed`) |
| `ah_runtime` | Non-owner read/write app user — **RLS applies to it** | All user-facing HTTP requests (web + API) on DBs 1–7, 9–13 |
| `ah_system` | Non-owner, **BYPASSRLS**, member of `ah_runtime` (inherits its grants) | Trusted, pre-/no-context subsystems: auth bootstrap, Filament admin, queue worker, console |
| `ah_readonly` | SELECT-only | `property_read`, `wildlife_read`, `geospatial_read`, `analytics` |
| `ah_etl` | ETL writes; owner of DBs 8 and 14 | `analytics_etl`, `research` |

**SEC-043 three-role model.** The application must never connect as the table owner at runtime — a table owner bypasses RLS unless `FORCE ROW LEVEL SECURITY` is set, which made every policy a silent no-op. User-facing requests connect as the non-owner `ah_runtime` so policies are enforced; subsystems that legitimately run before a per-user context exists (login/register/MFA, admin, queue jobs) connect as `ah_system` (BYPASSRLS). Role selection is centralized in `App\Database\ConnectionRole` and driven by `RuntimeDatabaseRoleProvider` (console: owner for schema commands, system otherwise; testing: owner) plus the `db.system` middleware (`UseSystemDatabaseRole`) on auth routes and the Filament panel. The connection-username defaults in `config/database.php` are `ah_runtime`; `.env` provides `DB_*_USERNAME` per connection plus `DB_APP_*` (owner) and `DB_SYSTEM_*`. Because docker-compose uses `env_file: .env`, changing these requires **recreating** the app container, not just `config:clear`.

### Read Replica Pattern

Three connections use `ah_readonly` to target read replicas (or the primary when no replica is configured):

- `property_read` — falls back to `DB_PROPERTY_HOST` if `DB_PROPERTY_READ_HOST` is not set
- `wildlife_read` — falls back to `DB_WILDLIFE_HOST` if `DB_WILDLIFE_READ_HOST` is not set
- `geospatial_read` — falls back to `DB_GEOSPATIAL_HOST` if `DB_GEOSPATIAL_READ_HOST` is not set

In dev (single PostgreSQL container), read replicas hit the same server as the writer — this is fine.

### ETL Connection Pattern

Two connections use `ah_etl` and are never used by application controllers, models, or services:

- `analytics_etl` — writes to `ah_analytics` (DB 8). Only `App\Jobs\Analytics\*` classes connect here.
- `research` — writes to `ah_research` (DB 14). Only `App\Jobs\Research\*` classes connect here.

The `analytics` connection also points to `ah_analytics` but uses `ah_readonly` — this is the connection used for reporting reads.

### SSL Mode

`DB_SSLMODE` controls SSL for all connections. Default: `prefer` (dev). Production: `require`.

```php
// In config/database.php — shared across all connections:
'sslmode' => env('DB_SSLMODE', 'prefer'),
```

---

## Using Database Connections in Code

### Raw queries — always specify the connection

```php
// WRONG — uses the default ('identity') regardless of what you want:
DB::table('leases')->where('status', 'active')->get();

// CORRECT — always specify:
DB::connection('lease')->table('leases')->where('status', 'active')->get();
```

### Eloquent models

Models declare their connection as a class property — Eloquent respects it automatically:

```php
class Lease extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'leases';
}

// Then:
Lease::where('status', 'active')->get();          // uses 'lease' connection
Lease::on('lease')->where('status', 'active')->get(); // explicit — preferred in services
```

### Migrations

```php
return new class extends Migration
{
    protected $connection = 'lease';  // mandatory

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            CREATE TABLE leases ( ... );
        SQL);
    }
};
```

### Seeders

```php
DB::connection('platform')->table('feature_flags')->insert([...]);
```

---

## Valkey Connections

`config/database.php` defines 5 named entries in the `redis` block. All use phpredis (Valkey is Redis-compatible). Each maps to a separate Docker container in dev and a separate cluster endpoint in production.

### Valkey Connection Map

| Connection key | Cluster | Purpose | Inside Docker (host:port) | Host port (dev) |
|---|---|---|---|---|
| `sessions` | Cluster 1 | User sessions, MFA state, magic link tokens | `valkey_sessions:6379` | `16379` |
| `default` | Cluster 2 | App cache — alias for `cache` | `valkey_cache:6379` | `16380` |
| `cache` | Cluster 2 | App cache — property listings, config, lease summaries | `valkey_cache:6379` | `16380` |
| `queue` | Cluster 3 | Job queue — `priority` and `default` named queues | `valkey_queue:6379` | `16381` |
| `auction` | Cluster 4 | Live bid state, countdowns, bid locks | `valkey_auction:6379` | `16382` |
| `ratelimit` | Cluster 5 | Per-user/per-IP API throttle counters | `valkey_ratelimit:6379` | `16383` |

**Why ports 16379–16383?** On Windows with WSL2, port 6379 is often bound by the host system's Redis process or WSL2 network stack. The 16379–16383 range avoids this conflict. Inside the Docker network, all containers still listen on the standard `:6379`.

The `default` and `cache` keys both point to the same container. `default` exists because some Laravel internals reference the `default` Redis connection. Always use `cache` or `Cache::store('valkey')` in application code — do not rely on the `default` naming.

### Using the Cache (Valkey Cluster 2)

```php
use Illuminate\Support\Facades\Cache;

// Read/write through the 'valkey' cache store (config/cache.php default):
$value = Cache::store('valkey')->remember(
    "lease_detail:{$leaseId}",
    now()->addMinutes(10),
    fn() => $this->buildLeaseDetail($leaseId)
);

// Invalidate on write:
Cache::store('valkey')->forget("lease_detail:{$leaseId}");

// Shorter form — 'valkey' is the default CACHE_STORE:
Cache::remember("property:detail:{$slug}", now()->addMinutes(15), fn() => ...);
Cache::forget("property:detail:{$slug}");
```

### Using Auction State (Valkey Cluster 4)

The auction cluster stores live bid state during active auctions. Access it via the `auction` Redis connection directly — not via the Cache facade:

```php
use Illuminate\Support\Facades\Redis;

$bidState = Redis::connection('auction')->hgetall("auction:{$auctionId}");
Redis::connection('auction')->hset("auction:{$auctionId}", 'high_bid', $amount);
Redis::connection('auction')->expire("auction:{$auctionId}", 86400);
```

### Using Rate Limiting (Valkey Cluster 5)

```php
$key    = "rate:{$userId}:bid_place";
$window = 3600; // 1 hour

$count = Redis::connection('ratelimit')->incr($key);
if ($count === 1) {
    Redis::connection('ratelimit')->expire($key, $window);
}

if ($count > 20) {
    abort(429, 'Too many bids in this window.');
}
```

---

## Sessions

Sessions use Valkey Cluster 1 (`sessions` connection). Configuration in `config/session.php`:

```php
'driver'     => env('SESSION_DRIVER', 'redis'),   // uses phpredis
'connection' => env('SESSION_CONNECTION', 'sessions'), // Cluster 1
'lifetime'   => (int) env('SESSION_LIFETIME', 120),   // minutes
```

The `sessions` connection is dedicated to session storage only. MFA state, magic link tokens, and other short-lived authentication state also live in this cluster. Failure of Cluster 1 forces re-authentication — it does not affect lease operations, billing, or the queue.

Sessions are serialized as JSON (`'serialization' => 'json'` in `config/session.php`). Do not store non-serializable PHP objects in session.

---

## Queue

The queue uses Valkey Cluster 3 (`queue` connection). Configuration in `config/queue.php`:

```php
'default' => env('QUEUE_CONNECTION', 'valkey'),

'connections' => [
    'valkey' => [
        'driver'      => 'redis',
        'connection'  => 'queue',          // Cluster 3
        'queue'       => ['priority', 'default'],
        'retry_after' => 90,
        'block_for'   => null,
        'after_commit' => false,
    ],
],
```

Two named queues share the same Valkey cluster:

| Queue | `retry_after` | Who processes it |
|---|---|---|
| `priority` | 30 seconds | SOS alerts, Stripe webhooks, e-sign webhooks, lease activation, OFAC results |
| `default` | 90 seconds | Email, SMS, push, video transcoding, AI tagging, ETL triggers, PDF/QR generation |

Worker command (defined in `docker/supervisor/supervisord.conf`, `numprocs=2`):

```bash
php artisan queue:work valkey --queue=priority,default --sleep=3 --tries=3 --max-time=3600
```

The `priority,default` order means the worker always drains the priority queue before processing default-queue jobs.

Failed jobs are stored in the `failed_jobs` table on the `identity` connection (DB 1). Job batches use the `job_batches` table on the same connection.

---

## RLS Context Injection

Row Level Security policies on several databases read `app.current_user_id` and `app.current_role` from the PostgreSQL session configuration. These are injected by the `InjectDatabaseContext` middleware on every authenticated request:

```php
// App\Http\Middleware\InjectDatabaseContext

public function handle(Request $request, Closure $next): Response
{
    if ($user = $request->user()) {
        $userId = $user->id;
        $role   = $user->primaryRole();

        $connections = [
            'identity', 'property', 'lease', 'billing',
            'wildlife', 'commerce', 'communications', 'incidents',
        ];

        foreach ($connections as $conn) {
            DB::connection($conn)->statement(
                "SELECT set_config('app.current_user_id', ?, false),
                        set_config('app.current_role', ?, false)",
                [$userId, $role]
            );
        }
    }

    return $next($request);
}
```

`set_config(..., false)` makes the setting local to the current transaction. Do not use `SET app.current_user_id = ?` (which is session-scoped and persists across transactions on pooled connections).

RLS policies are bypassed by `ah_system`, `ah_readonly`, and `ah_etl` (all have the `BYPASSRLS` privilege). User-facing application code connects as the non-owner `ah_runtime`, to which RLS **applies** — so the `app.current_user_id` / `app.user_role` context set above is load-bearing. The owner `ah_app` is used only for migrations/seeders (it bypasses RLS as owner, which is why it must never be a runtime connection — see SEC-043 in `security.md`).

---

## Encryption Key Injection

Fields marked `-- encrypted` in the schema docs are encrypted via `pgp_sym_encrypt` / `pgp_sym_decrypt` using per-database keys. Keys are injected by `DatabaseServiceProvider` into the PHP config at boot:

```php
// App\Providers\DatabaseServiceProvider

public function boot(): void
{
    // In production: keys come from Azure Key Vault
    // In dev: keys come from ENCRYPTION_KEY_* env vars
    $keys = [
        'identity'  => env('ENCRYPTION_KEY_IDENTITY'),
        'billing'   => env('ENCRYPTION_KEY_BILLING'),
        'lease'     => env('ENCRYPTION_KEY_LEASE'),
        'documents' => env('ENCRYPTION_KEY_DOCUMENTS'),
    ];

    config(['encryption_keys' => $keys]);
}
```

Access in code:

```php
$key = config('encryption_keys.billing');  // never log this value
$row = DB::connection('billing')->selectOne(
    "SELECT pgp_sym_encrypt(?, ?) AS enc",
    [$plaintext, $key]
);
```

Only four databases have encrypted columns: `identity` (phone, SSN fragments), `billing` (W-9 data), `lease` (access codes), and `documents` (sensitive doc metadata). The `HasEncryptedFields` trait on models handles this transparently — see `laravel_models.md`.

**Never log encryption key values, even in debug mode.** Never log the decrypted value of an encrypted field.

---

## Common Pitfalls

**`DB::table()` without a connection uses `identity`.** The `default` key in `config/database.php` is `identity`. A raw `DB::table('leases')` query will silently run against `ah_identity` and return zero rows or throw a "table does not exist" error. Always use `DB::connection('lease')->table('leases')`.

**Read replicas in dev are the primary.** `property_read`, `wildlife_read`, and `geospatial_read` fall back to the same host as the writer when `*_READ_HOST` is not set. This means dev behaves correctly — you do not need a separate replica container locally.

**Analytics (`analytics` connection) is read-only at the credential level.** `ah_readonly` cannot INSERT, UPDATE, or DELETE. Any attempt from application code to write to DB 8 throws a PostgreSQL permission error. Write to DB 8 only from `App\Jobs\Analytics\*` jobs using `analytics_etl`.

**Research (`research` connection) has no `ah_app` credential.** Application code that tries to use the `research` connection will receive a connection error because `ah_app` is not provisioned on `ah_research`. Only ETL jobs use this connection.

**Do not open a DB transaction, then call AuditService inside it.** `AuditService` writes to DB 9 (`audit` connection) — a different database from the transaction's connection. The audit write is not covered by the transaction and will commit even if the outer transaction rolls back. Always call `AuditService` after the primary operation succeeds, outside any transaction block.
