# American Headhunter

America's hunting lease marketplace. *Hunt Better. Lease Smarter.*

A full-stack SaaS platform connecting landowners with hunters — discovery, bidding, contracts, e-signatures, payments, field operations, safety, and compliance in one vertical platform.

---

## Start Here

If you're a developer (or Claude Code) picking this up:

1. **Read `CLAUDE.md` first** — it's the project orientation: architecture, conventions, non-negotiable rules, and a task→files lookup table.
2. **Read `docs/build_roadmap.md`** — the phased plan from local dev to production launch. Phases 1–4 are complete; see **Project Status** below.
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

## Project Status

Phases 1–4 are complete; Phase 5 (billing) is built out and pending live Stripe-key verification. Active work is the member profile system and billing polish. See `docs/build_roadmap.md` for slice-by-slice detail.

| Phase | Status |
|---|---|
| 1 — Project skeleton & local Docker stack | ✅ Complete |
| 2 — Identity (auth, MFA, RBAC, three-role RLS) | ✅ Complete |
| 3 — Platform & property foundation + discovery | ✅ Complete |
| 3.9 / 3.10 — Admin panel + platform user management | ✅ Complete |
| 4 — Lease lifecycle (apply → e-sign → activate, Dropbox Sign custom contracts, member check-in & stand map) | ✅ Complete |
| 4.9 — Member multi-template profile system | 🚧 In progress |
| 5 — Billing & payments (Stripe Checkout, Connect, webhooks, promotions, admin pricing, invoice projection) | ✅ Built — pending live-key verification |
| 6 — Wildlife & field operations | ⏭️ Next |
| 7–10 — Comms & safety, commerce, analytics, launch prep | ⬜ Planned |

## Running Locally

| Component | Where |
|---|---|
| App | `http://localhost` — Laravel 13 / PHP 8.4 |
| Mailpit | `http://localhost:8025` — local email capture |
| PostgreSQL 16 + PostGIS | 14 databases, PostGIS + pgcrypto + uuid-ossp enabled |
| Valkey ×5 | sessions, cache, queue, auction, ratelimit |
| Queue workers | 2× `queue:work valkey --queue=priority,default` via Supervisor |

```bash
make up      # start the full stack
make fresh   # rebuild + migrate + seed from scratch
make down    # stop
```

Also handy: `make migrate` · `make psql-<db>` · `make valkey-<cluster>` · `make flush-cache`. Run all commands from the project root.

**Local notes**
- Valkey containers expose host ports **16379–16383** (not 6379–6383) to avoid WSL2 conflicts; internal Docker traffic still uses 6379.
- Storage permissions are fixed automatically on container start via `docker/entrypoint.sh`.
