# American Headhunter

America's hunting lease marketplace. *Hunt Better. Lease Smarter.*

A full-stack SaaS platform connecting landowners with hunters — discovery, bidding, contracts, e-signatures, payments, field operations, safety, and compliance in one vertical platform.

---

## Start Here

If you're a developer (or Claude Code) picking this up:

1. **Read `CLAUDE.md` first** — it's the project orientation: architecture, conventions, non-negotiable rules, and a task→files lookup table.
2. **Read `docs/build_roadmap.md`** — the phased plan from local dev to production launch. We're starting at Phase 1.
3. **Reference `docs/` as needed** — every subsystem is documented in detail.

## Stack

Laravel 13 · PHP 8.4 · Filament 3 · Inertia.js + React · PostgreSQL 16 + PostGIS · Valkey · Mapbox · Stripe + Cashier + Connect · Dropbox Sign · Laravel Reverb · Garage (S3-compatible storage) · Docker

## Architecture in One Paragraph

14 purpose-built PostgreSQL databases, each isolated by security domain and compliance boundary. No cross-database SQL foreign keys — all multi-database assembly happens at the Laravel service layer. 5 Valkey clusters handle sessions, cache, queue, real-time auction state, and rate limiting separately. Geometry lives in PostGIS. Object storage is S3-compatible (Garage on-prem, Azure Blob when migrated). Runs in Docker locally and on Ubuntu VMs in production, with a configuration-only migration path to Azure.

## Documentation Map

| Location | Contents |
|---|---|
| `CLAUDE.md` | Project orientation — read first |
| `docs/build_roadmap.md` | Phased build plan with task checklists |
| `docs/data_model/` | All 14 database schemas + conventions README |
| `docs/laravel/` | Database config, migrations, models, services, dev docker-compose, env template |
| `docs/american_headhunter_scope.md` | Full product scope — 93 modules, 5 portals |
| `docs/design_system.md` | Visual identity — typography, color, components |
| `docs/american_headhunter_website.jsx` | Reference prototype (Topographic Editorial aesthetic) |
| `docs/signup_flows.md` | 6 account-type signup flows |
| `docs/membership_tiers.md` | Tiers, entitlements, admin-configurable pricing |
| `docs/promotions_strategy.md` | Launch promos, free periods, referrals, promo codes |
| `docs/pricing_schema_additions.md` | DB tables for plans, entitlements, promotions |
| `docs/communications_strategy.md` | In-platform messaging (Reverb) + Discord integration |
| `docs/storage_strategy.md` | Garage on-prem → Azure Blob migration path |
| `docs/deployment_strategy.md` | On-prem → hybrid → Azure migration stages |
| `docs/dockerfile.md`, `docs/docker_compose_prod.md`, `docs/onprem_docker_compose.md` | Production container configs |
| `docs/cicd_and_migration.md`, `docs/azure_migration.md` | CI/CD pipeline and Azure migration |

## Current Status

**Phase 1 complete** — project skeleton and local Docker stack are fully operational.

### What's running

| Component | Detail |
|---|---|
| App | `http://localhost` — Laravel 13 / PHP 8.4, HTTP 200 |
| Mailpit | `http://localhost:8025` — local email capture |
| PostgreSQL 16 + PostGIS | All 14 databases created, PostGIS + pgcrypto + uuid-ossp enabled |
| Valkey (×5) | Sessions, cache, queue, auction, ratelimit — all healthy |
| Queue workers | 2× `queue:work valkey --queue=priority,default` via Supervisor |

### Key files added in Phase 1

| File | Purpose |
|---|---|
| `Dockerfile.dev` | PHP 8.4-FPM + Nginx + Supervisor + Redis ext |
| `docker-compose.yml` | Full local dev topology |
| `docker/postgres/init.sql` + `init-postgis.sh` | DB + extension provisioning |
| `docker/entrypoint.sh` | Storage permission fix on Windows volumes |
| `config/database.php` | All 14 DB connections + 5 Valkey connections |
| `.env` / `.env.example` | Every platform variable |
| `Makefile` | `make up/down/fresh/migrate/psql-*/valkey-*` |
| `app/Console/Commands/MigrateAll.php` | `php artisan migrate:all [--fresh] [--seed]` |
| `app/Console/Commands/MigrateSingle.php` | `php artisan migrate:single <db>` |
| `database/migrations/<db>/` | 14 empty migration directories, one per database |
| `app/Models/<Domain>/` | 13 empty model namespaces |
| `app/Services/<Domain>/` | 11 empty service namespaces |

### Notes for Windows / Docker Desktop

- Valkey containers expose ports **16379–16383** on the host (not 6379–6383) to avoid WSL2 port conflicts. Internal Docker traffic still uses 6379.
- Storage permissions are fixed automatically on every container start via `docker/entrypoint.sh`.

### Next milestone — Phase 2

DB 1 Identity schema migrations + base Eloquent model classes + `ImmutableModel` for audit DB.

**To resume:** `make up` from `C:\Users\stewa\Projects\AmericanHeadhunter`
