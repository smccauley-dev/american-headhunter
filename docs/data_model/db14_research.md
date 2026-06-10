# DB 14 — Research Dataset

**Server:** Air-gapped dedicated PostgreSQL — no inbound connections from the application tier
**Encryption Key:** Key N — rotated annually
**Laravel Connection:** `research` (ETL job classes only)
**Database:** `ah_research`
**DB User:** `ah_etl` ONLY — `ah_app` has NO credentials for this database
**Access:** ETL jobs (`App\Jobs\Research\*`), licensed research partners (read-only direct DB connection or partner API), platform data science team

---

## CRITICAL RULES

1. **No application service EVER touches DB 14.** No controller, model, or service class in the application tier (`App\Http\`, `App\Services\`, `App\Models\`) should reference the `research` connection.
2. **No Eloquent models exist in `App\Models\Research\`.** ETL jobs use raw `DB::connection('research')` queries only.
3. **`ah_app` has no credentials for this database.** The `research` connection in `config/database.php` is configured with `ah_etl` credentials — if the application accidentally references it, it will fail with an authentication error. This is intentional.
4. **Zero PII, zero precise GPS.** All data is anonymized before writing here. User references are one-way hashed cohort IDs. GPS coordinates are bucketed to county FIPS — never stored as lat/lon.
5. **Data flows one-way.** ETL reads from DB 2, 3, 5, 8 → anonymizes → writes to DB 14. Nothing flows back from DB 14 into the application.

---

## Purpose

The anonymized, aggregated research dataset powering data licensing partnerships — universities, state wildlife agencies, conservation organizations, and commercial research buyers. Also supports the platform's own wildlife population modeling research. Air-gapped from the application layer by design. Governed by the `data_monetization` feature flag (`platform.feature_flags` in DB 12).

The separation of DB 14 from the application databases is a **compliance and legal boundary** — not merely an architectural preference. Anonymized research data must not be co-located with PII in any form.

---

## Air-Gap Architecture

```
Application Tier (ah_app user)
    |
    |  (one-way — no return path)
    ↓
ETL Jobs: App\Jobs\Research\* (ah_etl user)
    |  anonymize → validate → write
    ↓
DB 14: ah_research (isolated subnet)
    |
    |  (read-only connection — rate-limited, keyed)
    ↓
Research Partner API / Data Science Team
```

The ETL subnet has outbound access to DB 14 only. The application subnet has NO access to DB 14's host. Firewall rules enforce this at the network level.

---

## Anonymization Rules

Before any data lands in DB 14, the following transformations are applied by the ETL jobs:

| Source Field | Transformation | Research Field |
|---|---|---|
| `user.id` (UUID) | `hash_hmac('sha256', uuid, ETL_SALT)` — not reversible | `cohort_id VARCHAR(64)` |
| GPS coordinates | Bucketed to county FIPS code | `county_fips CHAR(5)` |
| Harvest weight (lbs) | Bucketed to ranges | `weight_bucket VARCHAR(20)` |
| Antler score | Bucketed to ranges | `antler_score_bucket VARCHAR(20)` |
| Property acreage | Bucketed to ranges | `property_size_bucket VARCHAR(20)` |
| Lease price | Bucketed to price-per-acre ranges | `price_per_acre_bucket VARCHAR(20)` |

The ETL salt (`ETL_SALT`) is a separate secret from all database encryption keys, stored in Azure Key Vault and rotated independently. Cohort IDs are consistent across ETL runs for the same user — enabling longitudinal analysis without ever storing the real user ID.

---

## Extensions Required

```sql
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

---

## Tables

### research_harvest_records
Anonymized harvest data. One row per individual harvest event, fully bucketed and de-identified. Source: DB 5 `harvest_logs`.

```sql
CREATE TABLE research_harvest_records (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    cohort_id               VARCHAR(64) NOT NULL,    -- HMAC-SHA256 of user UUID — not reversible
    state_code              CHAR(2) NOT NULL,
    county_fips             CHAR(5) NOT NULL,        -- FIPS code — never precise GPS
    species_code            VARCHAR(50) NOT NULL,
    harvest_year            SMALLINT NOT NULL,
    harvest_month           SMALLINT NOT NULL,       -- 1–12
    weapon_type             VARCHAR(30),             -- 'rifle', 'bow', 'muzzleloader', 'crossbow', 'shotgun'
    antler_score_bucket     VARCHAR(20),             -- 'none', 'under_100', '100_149', '150_199', '200_plus'
    weight_bucket           VARCHAR(20),             -- 'under_100lbs', '100_149lbs', '150_199lbs', '200_plus_lbs'
    property_size_bucket    VARCHAR(20),             -- 'under_50', '50_200', '200_500', '500_plus' (acres)
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No updated_at — research records are immutable once written
    -- No deleted_at — retained per data licensing agreements
);

CREATE INDEX idx_research_harvest_state ON research_harvest_records (state_code, harvest_year);
CREATE INDEX idx_research_harvest_species ON research_harvest_records (species_code, harvest_year);
CREATE INDEX idx_research_harvest_county ON research_harvest_records (county_fips, harvest_year);
CREATE INDEX idx_research_harvest_cohort ON research_harvest_records (cohort_id, harvest_year);
```

**Bucket definitions:**

```
antler_score_bucket:
  'none'       — no antlers or score not submitted
  'under_100'  — gross B&C score < 100
  '100_149'    — 100–149.9
  '150_199'    — 150–199.9
  '200_plus'   — 200+

weight_bucket:
  'under_100lbs'    — < 100 lbs
  '100_149lbs'      — 100–149 lbs
  '150_199lbs'      — 150–199 lbs
  '200_plus_lbs'    — 200+ lbs

property_size_bucket:
  'under_50'     — < 50 acres
  '50_200'       — 50–199 acres
  '200_500'      — 200–499 acres
  '500_plus'     — 500+ acres
```

---

### research_property_metrics
Anonymized property and market data. Supports lease pricing research and market trend analysis. Source: DB 2 `listings`, DB 3 `leases`.

```sql
CREATE TABLE research_property_metrics (
    id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code              CHAR(2) NOT NULL,
    county_fips             CHAR(5) NOT NULL,
    property_size_bucket    VARCHAR(20) NOT NULL,   -- Same buckets as harvest_records
    listing_type            VARCHAR(30) NOT NULL,   -- 'annual_lease', 'seasonal_lease', 'day_lease', 'club_lease'
    price_per_acre_bucket   VARCHAR(20) NOT NULL,   -- 'under_5', '5_15', '15_30', '30_50', '50_plus' ($/acre)
    days_to_lease           SMALLINT,               -- Days from listing to first signed lease — NULL if not leased
    season_year             SMALLINT NOT NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No updated_at — immutable
    -- No deleted_at — retained per data licensing agreements
);

CREATE INDEX idx_research_property_state ON research_property_metrics (state_code, season_year);
CREATE INDEX idx_research_property_county ON research_property_metrics (county_fips, season_year);
CREATE INDEX idx_research_property_type ON research_property_metrics (listing_type, season_year);
```

**Price per acre bucket definitions:**

```
price_per_acre_bucket:
  'under_5'    — < $5/acre/year
  '5_15'       — $5–$14.99/acre/year
  '15_30'      — $15–$29.99/acre/year
  '30_50'      — $30–$49.99/acre/year
  '50_plus'    — $50+/acre/year
```

---

### research_population_estimates
Wildlife population modeling inputs and estimates. Aggregated by county and species. Supports state agency partnerships and conservation research. Source: DB 5 `harvest_logs`, `wildlife_sightings`, `trail_camera_detections`; DB 8 `harvest_analytics`.

```sql
CREATE TABLE research_population_estimates (
    id                          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code                  CHAR(2) NOT NULL,
    county_fips                 CHAR(5) NOT NULL,
    species_code                VARCHAR(50) NOT NULL,
    data_year                   SMALLINT NOT NULL,
    harvest_count               INT NOT NULL DEFAULT 0,     -- Total confirmed harvests in this county/year
    sighting_count              INT NOT NULL DEFAULT 0,     -- Confirmed wildlife sightings logged
    camera_detection_rate       NUMERIC(6,4),               -- Average detections per camera-day (NULL if no cameras)
    estimated_population_density NUMERIC(8,4),              -- Estimated individuals per square mile (modeled)
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_research_population_estimates
        UNIQUE (state_code, county_fips, species_code, data_year)
);

CREATE INDEX idx_research_population_state ON research_population_estimates (state_code, data_year);
CREATE INDEX idx_research_population_county ON research_population_estimates (county_fips, species_code);
CREATE INDEX idx_research_population_species ON research_population_estimates (species_code, data_year);
```

---

## ETL Job Classes

ETL jobs are the only code that writes to DB 14. They run on the `default` queue and are triggered by the nightly ETL pipeline after DB 8 is populated.

```
App\Jobs\Research\
├── SyncHarvestResearchData.php       -- Anonymizes DB 5 harvest_logs → research_harvest_records
├── SyncPropertyResearchMetrics.php   -- Anonymizes DB 2+3 listing/lease data → research_property_metrics
└── SyncPopulationEstimates.php       -- Aggregates DB 5+8 wildlife data → research_population_estimates
```

ETL jobs use raw `DB::connection('research')` — no Eloquent models:

```php
namespace App\Jobs\Research;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class SyncHarvestResearchData implements ShouldQueue
{
    use Queueable;

    public $queue = 'default';

    public function handle(): void
    {
        // Read from DB 5 (wildlife) — production data
        $harvests = DB::connection('wildlife')
            ->table('harvest_logs')
            ->where('logged_at', '>=', now()->subDay()->startOfDay())
            ->where('logged_at', '<',  now()->startOfDay())
            ->get();

        $rows = $harvests->map(function ($h) {
            return [
                'id'                   => (string) \Illuminate\Support\Str::uuid(),
                'cohort_id'            => hash_hmac('sha256', $h->hunter_user_id, config('research.etl_salt')),
                'state_code'           => $h->state_code,
                'county_fips'          => $this->resolveCountyFips($h),  // from DB 13 geospatial
                'species_code'         => $h->species_code,
                'harvest_year'         => (int) date('Y', strtotime($h->harvested_at)),
                'harvest_month'        => (int) date('n', strtotime($h->harvested_at)),
                'weapon_type'          => $h->weapon_type,
                'antler_score_bucket'  => $this->scoreToAntlerBucket($h->gross_score),
                'weight_bucket'        => $this->weightToWeightBucket($h->weight_lbs),
                'property_size_bucket' => $this->acreageToBucket($h->property_acres),
                'created_at'           => now(),
            ];
        })->toArray();

        // Write to DB 14 (research) — anonymized
        if (!empty($rows)) {
            DB::connection('research')
                ->table('research_harvest_records')
                ->insertOrIgnore($rows);
        }
    }

    private function scoreToAntlerBucket(?float $score): string
    {
        if ($score === null) return 'none';
        if ($score < 100)   return 'under_100';
        if ($score < 150)   return '100_149';
        if ($score < 200)   return '150_199';
        return '200_plus';
    }

    private function weightToWeightBucket(?float $weightLbs): ?string
    {
        if ($weightLbs === null) return null;
        if ($weightLbs < 100)   return 'under_100lbs';
        if ($weightLbs < 150)   return '100_149lbs';
        if ($weightLbs < 200)   return '150_199lbs';
        return '200_plus_lbs';
    }

    private function acreageToBucket(?float $acres): ?string
    {
        if ($acres === null) return null;
        if ($acres < 50)    return 'under_50';
        if ($acres < 200)   return '50_200';
        if ($acres < 500)   return '200_500';
        return '500_plus';
    }

    private function resolveCountyFips(object $harvest): string
    {
        // Looks up county FIPS from DB 13 geospatial using the harvest GPS point
        // Returns '00000' if geospatial lookup fails — never blocks the ETL
        // ...
        return '00000';
    }
}
```

---

## Database Configuration

In `config/database.php`, the `research` connection is configured with `ah_etl` credentials. The application's standard `ah_app` user has no entry point into this database.

```php
// config/database.php (relevant excerpt)

'research' => [
    'driver'   => 'pgsql',
    'host'     => env('DB_RESEARCH_HOST'),
    'port'     => env('DB_RESEARCH_PORT', '5432'),
    'database' => 'ah_research',
    'username' => env('DB_RESEARCH_ETL_USER'),    // ah_etl — NOT ah_app
    'password' => env('DB_RESEARCH_ETL_PASSWORD'),
    'charset'  => 'utf8',
    'schema'   => 'public',
],
```

The `DB_RESEARCH_ETL_USER` and `DB_RESEARCH_ETL_PASSWORD` environment variables are set **only** in the ETL service's container environment. They are absent from the web application container environment entirely — so even if application code accidentally calls `DB::connection('research')`, the connection will fail with a missing-credentials error.

---

## Research Partner API

Licensed research partners access data via a dedicated read-only API service (separate from the main application):

```
Research Partner API (isolated service)
    ↓
Direct read-only connection to ah_research
    — PostgreSQL user: ah_research_reader (SELECT only)
    — Rate-limited: per-partner API key
    — Data license agreement enforced at application level
    — All queries logged to partner_access_log table in ah_research
```

The research partner API is not part of the main Laravel application. It is a separate service with its own deployment. Application code in this repository does not serve research data to partners.

---

## Retention

Research records are retained per the terms of individual data licensing agreements — minimum 5 years, typically indefinite for population modeling datasets. Deletion requests from individuals cannot result in deletion from DB 14 because records are fully de-identified and cannot be linked back to a specific user (the cohort_id hash is one-way and the salt is not stored in DB 14).

---

## Common Pitfalls

- **Never add a model to `App\Models\Research\`.** DB 14 has no Eloquent models. ETL jobs use raw queries only.
- **Never call `DB::connection('research')` from application code.** If you see this outside `App\Jobs\Research\*`, it is a bug.
- **The `research` connection credentials are not in the web container's environment.** Attempting to open this connection from application code will fail immediately. This is by design.
- **`cohort_id` is not reversible.** You cannot look up a user from a cohort_id. This is intentional — the hash is one-way and the ETL salt is not stored in DB 14.
- **County FIPS, not GPS.** Research records store `county_fips` only — never latitude or longitude. If an ETL job stores precise coordinates, it is a data leak and a compliance violation.
- **ETL jobs must be idempotent.** Re-running an ETL job for the same date range should produce identical output. Use `insertOrIgnore()` or `upsert()` accordingly.
