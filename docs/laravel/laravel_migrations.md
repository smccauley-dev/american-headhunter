# Laravel Migrations — Structure & Conventions

This document covers how to write, run, and roll back migrations in the American Headhunter platform. The multi-database architecture and PostgreSQL-specific schema requirements drive most of the conventions here. Read this before writing any migration.

---

## File Location

```
database/migrations/<connection>/YYYY_MM_DD_HHMMSS_description.php
```

Each database has its own subdirectory named after its Laravel connection key:

```
database/migrations/
├── identity/          -- DB 1
├── platform/          -- DB 12
├── geospatial/        -- DB 13
├── property/          -- DB 2
├── lease/             -- DB 3
├── billing/           -- DB 4
├── wildlife/          -- DB 5
├── commerce/          -- DB 6
├── communications/    -- DB 7
├── audit/             -- DB 9
├── incidents/         -- DB 10
├── documents/         -- DB 11
├── analytics/         -- DB 8
└── research/          -- DB 14
```

---

## The `$connection` Property Is Mandatory

Every migration class must declare its connection. Without it, Laravel uses the default (`identity`), and the migration runs against the wrong database.

```php
return new class extends Migration
{
    protected $connection = 'lease';   // MANDATORY — never omit

    public function up(): void { ... }
    public function down(): void { ... }
};
```

---

## Always Use Raw SQL — Never the Schema Builder

Use `DB::connection($this->connection)->unprepared(<<<'SQL' ... SQL)` for any DDL block that contains **more than one statement**. Use `statement()` only for single-statement calls (e.g., a lone `DROP TABLE`). Do **not** use `Schema::create()`, `Schema::table()`, or `Blueprint`.

**`unprepared()` vs `statement()`:**
- `statement()` uses PDO prepared statements, which PostgreSQL rejects when the string contains multiple semicolon-separated commands: `"cannot insert multiple commands into a prepared statement"`.
- `unprepared()` uses `PDO::exec()` (simple query mode), which handles multiple statements correctly.
- Always use single-quoted heredoc `<<<'SQL'` to prevent PHP from interpreting `$` characters (dollar-quoting in plpgsql functions uses `$$`).

**Why raw SQL?**

- PostgreSQL enums — Laravel's Schema builder creates CHAR/VARCHAR columns, not proper PostgreSQL `ENUM` types. This project uses `VARCHAR + CHECK CONSTRAINT` for enum-like columns (avoids ALTER TYPE pain) or native PostgreSQL `CREATE TYPE ... AS ENUM` where appropriate. Either must be written in raw SQL.
- PostGIS geometry types — `geometry(Polygon, 4326)`, `geography`, `ST_*` functions cannot be expressed in the Schema builder.
- RLS policies — `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` and `CREATE POLICY` have no Schema builder equivalent.
- Immutability rules — `CREATE RULE no_update ... DO INSTEAD NOTHING` is PostgreSQL-specific.
- Triggers — `CREATE TRIGGER ... EXECUTE FUNCTION trigger_set_updated_at()` must be raw SQL.
- Extensions — `CREATE EXTENSION IF NOT EXISTS "pgcrypto"` must be raw SQL.
- PostgreSQL-specific index types — `USING GIN`, `USING GiST`, `USING BRIN` are not fully supported by the Schema builder.

---

## Standard Migration Structure

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_applications (
                id                  UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                property_id         UUID        NOT NULL,   -- References DB 2 (Property) properties.id
                applicant_user_id   UUID        NOT NULL,   -- References DB 1 (Identity) users.id
                application_type    VARCHAR(20) NOT NULL
                    CHECK (application_type IN ('individual', 'club', 'outfitter')),
                status              VARCHAR(30) NOT NULL DEFAULT 'submitted'
                    CHECK (status IN (
                        'submitted', 'under_review', 'info_requested',
                        'approved', 'rejected', 'withdrawn', 'expired'
                    )),
                requested_start     DATE        NOT NULL,
                requested_end       DATE        NOT NULL,
                hunter_count        INT         NOT NULL DEFAULT 1 CHECK (hunter_count >= 1),
                message             TEXT,
                reviewed_by         UUID,                   -- References DB 1 (Identity) users.id
                reviewed_at         TIMESTAMPTZ,
                expires_at          TIMESTAMPTZ,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at          TIMESTAMPTZ
            );

            CREATE INDEX idx_lease_applications_property_id ON lease_applications (property_id);
            CREATE INDEX idx_lease_applications_applicant   ON lease_applications (applicant_user_id);
            CREATE INDEX idx_lease_applications_status      ON lease_applications (status) WHERE deleted_at IS NULL;

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON lease_applications
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        // Single statement — statement() is fine here
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS lease_applications'
        );
    }
};
```

---

## Extensions Migration (First in Every Database)

Every database needs its extensions before any other migration runs. Name this file `YYYY_MM_DD_000001_create_extensions.php`:

```php
return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // Each CREATE EXTENSION must be its own statement() call —
        // PDO prepared statements reject multiple commands in one call.
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "citext"');

        // Shared trigger function — created once per database, referenced by all tables.
        // Single-quoted heredoc prevents PHP from expanding $$ dollar-quoting.
        $conn->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trigger_set_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP FUNCTION IF EXISTS trigger_set_updated_at() CASCADE;'
        );
    }
};
```

---

## Timestamp Trigger Pattern

All tables use PostgreSQL triggers to maintain `updated_at` instead of Laravel's `$timestamps = true`. This means every migration that creates a table with `updated_at` must also create the trigger:

```sql
-- Trigger created once per DB in the extensions migration:
CREATE OR REPLACE FUNCTION trigger_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Applied to each table individually:
CREATE TRIGGER set_updated_at
    BEFORE UPDATE ON <table_name>
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

Because timestamps are managed by PostgreSQL triggers, all models set `public $timestamps = false` and manually cast `created_at`, `updated_at`, and `deleted_at` in `casts()`. See `laravel_models.md`.

---

## PostGIS Migration (Geospatial Database)

```php
return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        // Extensions first (separate migration file):
        DB::connection($this->connection)->statement(<<<SQL
            CREATE EXTENSION IF NOT EXISTS "postgis";
            CREATE EXTENSION IF NOT EXISTS "postgis_topology";
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
            CREATE EXTENSION IF NOT EXISTS "pgcrypto";
        SQL);
    }
};
```

Table with geometry column:

```php
return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            CREATE TABLE property_boundaries (
                id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                property_id     UUID        NOT NULL UNIQUE, -- References DB 2 (Property) properties.id
                boundary_geom   geometry(MultiPolygon, 4326) NOT NULL,
                area_acres      NUMERIC(10,2),
                source          VARCHAR(20) NOT NULL DEFAULT 'manual'
                    CHECK (source IN ('manual', 'parcel_api', 'gps_import', 'drawn')),
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            -- GiST index for spatial queries (ST_DWithin, ST_Intersects, etc.)
            CREATE INDEX idx_property_boundaries_geom
                ON property_boundaries USING GIST (boundary_geom);

            CREATE INDEX idx_property_boundaries_property_id
                ON property_boundaries (property_id);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON property_boundaries
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS property_boundaries;'
        );
    }
};
```

---

## Audit Database — Immutability Rules

DB 9 tables are append-only. Every table in the `audit` database must have a PostgreSQL RULE blocking UPDATE and DELETE at the database engine level. The `ImmutableModel` base class also blocks writes at the PHP level, but the RULE is the authoritative enforcement.

```php
return new class extends Migration
{
    protected $connection = 'audit';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            CREATE TABLE audit_log (
                id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                event_type      VARCHAR(30) NOT NULL,
                source_database VARCHAR(50) NOT NULL,
                table_name      VARCHAR(100) NOT NULL,
                record_id       UUID        NOT NULL,
                user_id         UUID,
                session_id      VARCHAR(100),
                action_summary  TEXT,
                changed_fields  JSONB,
                old_values      JSONB,
                new_values      JSONB,
                ip_address      INET,
                user_agent      TEXT,
                occurred_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            -- Immutability — block UPDATE and DELETE at the database level
            -- These rules fire INSTEAD of the attempted modification, making it a no-op.
            CREATE RULE no_update_audit_log
                AS ON UPDATE TO audit_log DO INSTEAD NOTHING;
            CREATE RULE no_delete_audit_log
                AS ON DELETE TO audit_log DO INSTEAD NOTHING;

            -- Indexes for AuditLogResource viewer
            CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at DESC);
            CREATE INDEX idx_audit_log_user_id      ON audit_log (user_id);
            CREATE INDEX idx_audit_log_record       ON audit_log (source_database, table_name, record_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            DROP RULE IF EXISTS no_update_audit_log ON audit_log;
            DROP RULE IF EXISTS no_delete_audit_log ON audit_log;
            DROP TABLE IF EXISTS audit_log;
        SQL);
    }
};
```

---

## RLS Policies Migration (Last in Each Database)

Row Level Security is applied in a dedicated migration that runs after all tables are created. This is always the last migration file in the database's directory.

```php
return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            ALTER TABLE users ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_profiles ENABLE ROW LEVEL SECURITY;

            -- Users can read their own record
            CREATE POLICY users_self_read ON users
                FOR SELECT
                USING (id = current_setting('app.current_user_id', true)::UUID);

            -- Admins and staff can read all
            CREATE POLICY users_admin_read ON users
                FOR SELECT
                USING (
                    current_setting('app.current_role', true)
                    IN ('super_admin', 'platform_admin', 'platform_staff')
                );

            -- Users can update only their own record
            CREATE POLICY users_self_write ON users
                FOR UPDATE
                USING (id = current_setting('app.current_user_id', true)::UUID);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(<<<SQL
            DROP POLICY IF EXISTS users_self_read   ON users;
            DROP POLICY IF EXISTS users_admin_read  ON users;
            DROP POLICY IF EXISTS users_self_write  ON users;
            ALTER TABLE users DISABLE ROW LEVEL SECURITY;
            ALTER TABLE user_profiles DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
```

---

## Common Pitfalls

### PostgreSQL UNION type inference with mixed-NULL columns

When seeding data via `UNION ALL` where the same column position holds different types across rows (e.g., `int_value` is `3` in one row and `NULL` in another while `bool_value` is `NULL` vs `false`), PostgreSQL cannot infer a consistent column type across the union and throws:

```
ERROR: UNION types text and boolean cannot be matched
```

**Fix:** Do not seed mixed-type data via SQL `UNION ALL`. Use PHP instead — build an array and call `DB::connection()->table()->insert()` in chunks. This is the correct pattern for any migration that seeds rows where different columns are populated per row:

```php
public function up(): void
{
    // Create table via unprepared() as normal...
    DB::connection($this->connection)->unprepared(<<<'SQL'
        CREATE TABLE feature_entitlements ( ... );
    SQL);

    // Seed mixed-type rows via PHP, not SQL UNION ALL
    $rows = [
        ['plan_id' => $id, 'feature_key' => 'max_listings', 'feature_type' => 'integer', 'bool_value' => null, 'int_value' => 5],
        ['plan_id' => $id, 'feature_key' => 'analytics',    'feature_type' => 'boolean', 'bool_value' => true,  'int_value' => null],
    ];
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection($this->connection)->table('feature_entitlements')->insert($chunk);
    }
}
```

### PHP heredoc indentation and multi-line SQL strings

PHP 7.3+ flexible heredoc requires every body line to be indented at least as much as the closing marker. If your `<<<'SQL'` block closes with `        SQL` (8 spaces), any line inside that starts at column 0 (like embedded HTML or long SQL strings) will cause a `ParseError: Invalid body indentation level`.

**Fix:** Never embed large multiline strings (HTML, JSON) directly inside a `<<<'SQL'` heredoc. Move such content to a seeder class where it can be assigned to a PHP variable cleanly, then inserted via `DB::connection()->table()->insert()`.

---

## Cross-Database Reference Convention

Tables frequently reference entities in other databases. Because no FK constraints can cross databases, these are plain UUID columns with a SQL comment documenting the source:

```sql
-- In the lease database referencing the property database:
property_id     UUID NOT NULL,  -- References DB 2 (Property) properties.id
lessee_user_id  UUID NOT NULL,  -- References DB 1 (Identity) users.id
```

Never add a PostgreSQL FOREIGN KEY constraint to a column that references another database. It will fail at the database level.

---

## Naming Conventions

| Object | Convention | Example |
|---|---|---|
| Tables | `snake_case`, plural | `lease_applications`, `harvest_logs` |
| Primary key | `id UUID` | `id UUID PRIMARY KEY DEFAULT gen_random_uuid()` |
| Standard index | `idx_<table>_<columns>` | `idx_lease_applications_status` |
| Unique index | `uq_<table>_<columns>` | `uq_users_email` |
| GIN index | `idx_<table>_<col>_gin` | `idx_documents_tags_gin` |
| GiST index | `idx_<table>_<col>_gist` | `idx_property_boundaries_geom_gist` |
| Trigger | `set_updated_at` (one per table) | standard across all tables |
| CHECK constraint | inline in column definition | `CHECK (status IN ('active', 'expired'))` |
| Migration class | anonymous class via `return new class` | matches filename |

---

## Migration Commands

### Run all 14 databases in dependency order

```bash
php artisan migrate:all
php artisan migrate:all --fresh           # drops all tables first
php artisan migrate:all --fresh --seed    # drops, migrates, seeds
```

The dependency order is: identity → platform → geospatial → property → lease → billing → wildlife → commerce → communications → audit → incidents → documents → analytics → research.

The `analytics` path uses the `analytics_etl` connection (DDL requires write access); `research` uses the `research` connection.

### Migrate a single database

```bash
php artisan migrate:single identity
php artisan migrate:single geospatial --fresh
php artisan migrate:single lease --fresh --seed
```

### Rollback a single database

```bash
php artisan migrate:rollback --database=identity --path=database/migrations/identity
php artisan migrate:rollback --database=lease --path=database/migrations/lease
```

### Status check

```bash
php artisan migrate:status --database=identity --path=database/migrations/identity
php artisan migrate:status --database=lease   --path=database/migrations/lease
```

---

## Seeder Conventions

Seeders use explicit connection calls — never rely on the default connection:

```php
class FeatureFlagsSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('platform')->table('feature_flags')->insert([
            ['id' => Str::uuid(), 'flag_key' => 'auction_module',      'is_enabled' => true,  'rollout_pct' => 100, ...],
            ['id' => Str::uuid(), 'flag_key' => 'consulting_marketplace', 'is_enabled' => true, 'rollout_pct' => 100, ...],
            ['id' => Str::uuid(), 'flag_key' => 'carbon_credits',      'is_enabled' => false, 'rollout_pct' => 0,   ...],
        ]);
    }
}
```

The master `DatabaseSeeder` calls domain seeders in dependency order — identity and platform seeders first, then reference data, then domain-specific seeders.

---

## Makefile Shortcuts

```bash
make migrate              # php artisan migrate:all
make migrate-fresh        # php artisan migrate:all --fresh
make migrate-seed         # php artisan migrate:all --fresh --seed
make migrate-single DB=lease   # php artisan migrate:single lease
```
