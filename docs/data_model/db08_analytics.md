# DB 8 — Analytics & Reporting

**Server:** Read-optimized PostgreSQL (columnar-friendly, no RLS)
**Encryption Key:** Key H — rotated annually
**Laravel Connection:** `analytics` (reads, `ah_readonly` user) / `analytics_etl` (ETL writes only)
**Database:** `ah_analytics`
**Access:** ETL jobs (write), BI tools, analysts, platform operators, admin dashboards (all read-only)

---

## CRITICAL RULE

**The application NEVER writes to this database. ETL jobs only.**

The `analytics` connection uses the `ah_readonly` PostgreSQL user — it has `SELECT` privileges only. Application services, controllers, and models MUST use `DB::connection('analytics')` for reads and must never reference `analytics_etl`. Only job classes in `App\Jobs\Etl\` may use `analytics_etl`.

```php
// CORRECT — reading analytics from an application service
$metrics = DB::connection('analytics')
    ->table('platform_daily_metrics')
    ->where('metric_date', today())
    ->first();

// WRONG — never reference analytics_etl from application code
DB::connection('analytics_etl')->table(...); // do not do this
```

---

## Purpose

Pre-computed, denormalized reporting data populated nightly by ETL jobs. Heavy analytical queries never run against production transactional databases. No PII — all data is aggregated, anonymized, or referenced only by UUID before landing here. Supports the Reporting Suite portal (`/reports`) and Filament admin dashboards.

---

## ETL Schedule

| Source DBs | Target Tables | Frequency |
|---|---|---|
| DB 2, DB 3 | `property_metrics` | Nightly 02:00 CST |
| DB 3, DB 4 | `lease_metrics`, `revenue_summary` | Nightly 02:15 CST |
| DB 1 | `user_metrics` | Nightly 02:30 CST |
| All DBs | `platform_daily_metrics` | Nightly 03:00 CST |
| DB 5 | `harvest_analytics` | Nightly 02:45 CST |
| DB 2, DB 3 | `search_analytics` | Nightly 02:10 CST |

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

### property_metrics
Daily rollup per property listing. Replaced on each ETL run for the prior day.

```sql
CREATE TABLE property_metrics (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    listing_id          UUID NOT NULL,              -- References DB 2 (Property) listings.id
    metric_date         DATE NOT NULL,
    views_count         INT NOT NULL DEFAULT 0,
    unique_views_count  INT NOT NULL DEFAULT 0,
    application_count   INT NOT NULL DEFAULT 0,
    save_count          INT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_property_metrics_listing_date UNIQUE (listing_id, metric_date)
);

CREATE INDEX idx_property_metrics_listing_id ON property_metrics (listing_id);
CREATE INDEX idx_property_metrics_date ON property_metrics (metric_date);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON property_metrics
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### lease_metrics
Per-lease financial and activity summary. Daily granularity.

```sql
CREATE TABLE lease_metrics (
    id              UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id        UUID NOT NULL,              -- References DB 3 (Lease) leases.id
    metric_date     DATE NOT NULL,
    check_in_count  INT NOT NULL DEFAULT 0,
    harvest_count   INT NOT NULL DEFAULT 0,
    revenue_cents   BIGINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_lease_metrics_lease_date UNIQUE (lease_id, metric_date)
);

CREATE INDEX idx_lease_metrics_lease_id ON lease_metrics (lease_id);
CREATE INDEX idx_lease_metrics_date ON lease_metrics (metric_date);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON lease_metrics
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### user_metrics
Per-user activity rollup. No PII — referenced only by UUID. Daily granularity.

```sql
CREATE TABLE user_metrics (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id             UUID NOT NULL,              -- References DB 1 (Identity) users.id
    metric_date         DATE NOT NULL,
    messages_sent       INT NOT NULL DEFAULT 0,
    listings_viewed     INT NOT NULL DEFAULT 0,
    bids_placed         INT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_user_metrics_user_date UNIQUE (user_id, metric_date)
);

CREATE INDEX idx_user_metrics_user_id ON user_metrics (user_id);
CREATE INDEX idx_user_metrics_date ON user_metrics (metric_date);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON user_metrics
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### platform_daily_metrics
Platform-wide KPIs. One row per day. Source of truth for the admin dashboard top-level cards.

```sql
CREATE TABLE platform_daily_metrics (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    metric_date         DATE NOT NULL,
    active_listings     INT NOT NULL DEFAULT 0,
    new_applications    INT NOT NULL DEFAULT 0,
    new_leases          INT NOT NULL DEFAULT 0,
    gmv_cents           BIGINT NOT NULL DEFAULT 0,
    new_users           INT NOT NULL DEFAULT 0,
    active_users        INT NOT NULL DEFAULT 0,
    sos_events          INT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_platform_daily_metrics_date UNIQUE (metric_date)
);

CREATE INDEX idx_platform_daily_metrics_date ON platform_daily_metrics (metric_date);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON platform_daily_metrics
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### harvest_analytics
Aggregated harvest data for reporting and conservation dashboards. One row per state/species/season combination.

```sql
CREATE TABLE harvest_analytics (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code          CHAR(2) NOT NULL,
    species_code        VARCHAR(50) NOT NULL,
    season_year         SMALLINT NOT NULL,
    total_harvest       INT NOT NULL DEFAULT 0,
    avg_antler_score    NUMERIC(6,2),
    harvest_by_weapon   JSONB NOT NULL DEFAULT '{}',    -- {"rifle": 142, "bow": 88, "muzzleloader": 31}
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_harvest_analytics_state_species_season UNIQUE (state_code, species_code, season_year)
);

CREATE INDEX idx_harvest_analytics_state ON harvest_analytics (state_code, season_year);
CREATE INDEX idx_harvest_analytics_species ON harvest_analytics (species_code, season_year);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON harvest_analytics
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### revenue_summary
Financial reporting aggregated by period. Supports the Reporting Suite financial summary views.

```sql
CREATE TABLE revenue_summary (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    period_type             VARCHAR(10) NOT NULL,
    period_start            DATE NOT NULL,
    period_end              DATE NOT NULL,
    gross_revenue_cents     BIGINT NOT NULL DEFAULT 0,
    platform_fees_cents     BIGINT NOT NULL DEFAULT 0,
    refunds_cents           BIGINT NOT NULL DEFAULT 0,
    net_revenue_cents       BIGINT NOT NULL DEFAULT 0,
    payout_total_cents      BIGINT NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_revenue_summary_period_type
        CHECK (period_type IN ('daily', 'monthly', 'annual'))
);

CREATE INDEX idx_revenue_summary_period ON revenue_summary (period_type, period_start);
CREATE INDEX idx_revenue_summary_start ON revenue_summary (period_start);

CREATE TRIGGER set_updated_at BEFORE UPDATE ON revenue_summary
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

---

### search_analytics
What users search for — used for product decisions, property inventory gaps, and marketing. No user IDs stored.

```sql
CREATE TABLE search_analytics (
    id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    search_date         DATE NOT NULL,
    search_query        TEXT NOT NULL,
    state_code          CHAR(2),
    result_count        INT NOT NULL DEFAULT 0,
    click_through_count INT NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No updated_at — rows are immutable once written by ETL
);

CREATE INDEX idx_search_analytics_date ON search_analytics (search_date);
CREATE INDEX idx_search_analytics_state ON search_analytics (state_code, search_date)
    WHERE state_code IS NOT NULL;
CREATE INDEX idx_search_analytics_query_gin ON search_analytics USING GIN (to_tsvector('english', search_query));
```

---

## Eloquent Models

All analytics models extend `ReadOnlyModel`, which throws a `LogicException` on any write attempt. This is a secondary safeguard — the `ah_readonly` database user enforces it at the PostgreSQL level.

```php
namespace App\Models\Analytics;

// Base class — all analytics models extend this
abstract class ReadOnlyModel extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'analytics';
    public $timestamps    = false;
    public $incrementing  = false;
    protected $keyType    = 'string';

    public function save(array $options = []): bool
    {
        throw new \LogicException('Analytics models are read-only. Use ETL jobs to populate this database.');
    }

    public function delete(): bool
    {
        throw new \LogicException('Analytics models are read-only.');
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        throw new \LogicException('Analytics models are read-only.');
    }
}
```

```php
namespace App\Models\Analytics;

class PropertyMetric extends ReadOnlyModel
{
    protected $table = 'property_metrics';

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class LeaseMetric extends ReadOnlyModel
{
    protected $table = 'lease_metrics';

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class UserMetric extends ReadOnlyModel
{
    protected $table = 'user_metrics';

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class PlatformDailyMetric extends ReadOnlyModel
{
    protected $table = 'platform_daily_metrics';

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class HarvestAnalytic extends ReadOnlyModel
{
    protected $table = 'harvest_analytics';

    protected function casts(): array
    {
        return [
            'harvest_by_weapon' => 'array',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class RevenueSummary extends ReadOnlyModel
{
    protected $table = 'revenue_summary';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }
}
```

```php
namespace App\Models\Analytics;

class SearchAnalytic extends ReadOnlyModel
{
    protected $table = 'search_analytics';

    protected function casts(): array
    {
        return [
            'search_date' => 'date',
            'created_at'  => 'datetime',
        ];
    }
}
```

---

## ETL Job Classes

ETL jobs live in `App\Jobs\Etl\` and are the only code that uses `analytics_etl`. They run on the `default` queue.

```
App\Jobs\Etl\
├── SyncPropertyMetrics.php
├── SyncLeaseMetrics.php
├── SyncUserMetrics.php
├── SyncPlatformDailyMetrics.php
├── SyncHarvestAnalytics.php
├── SyncRevenueSummary.php
└── SyncSearchAnalytics.php
```

ETL jobs use `DB::connection('analytics_etl')` with upsert:

```php
DB::connection('analytics_etl')
    ->table('property_metrics')
    ->upsert(
        $rows,
        ['listing_id', 'metric_date'],   // conflict columns
        ['views_count', 'unique_views_count', 'application_count', 'save_count', 'updated_at']
    );
```

---

## Common Pitfalls

- **Do not inject `analytics_etl` into application services.** It is only referenced inside `App\Jobs\Etl\` job classes.
- **Do not call `DB::connection('analytics')` with INSERT/UPDATE/DELETE.** The `ah_readonly` user will throw a permission error.
- **Do not use Eloquent `::create()`, `::update()`, or `::delete()` on any analytics model.** `ReadOnlyModel` will throw before the query reaches the database.
- **ETL jobs must be idempotent.** They use `upsert()` — re-running for the same date should produce identical results.
