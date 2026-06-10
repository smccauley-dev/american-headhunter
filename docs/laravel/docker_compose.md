# Docker Compose — Local Development Environment

This is the reference for the local dev Docker stack. One command (`make up`) starts everything needed for full-stack development: the application, all 14 PostgreSQL databases on a single server, 5 Valkey clusters, and Mailpit for email capture.

This setup is **dev-only**. For production, see `docs/docker_compose_prod.md` and `docs/onprem_docker_compose.md`.

---

## What Is Running

| Service | Container name | Image | Purpose |
|---|---|---|---|
| `app` | (project-name-app) | Custom `Dockerfile.dev` | PHP 8.4-FPM + Nginx + Supervisor |
| `postgres` | (project-name-postgres) | `postgis/postgis:16-3.4` | All 14 databases on one PostgreSQL server |
| `valkey_sessions` | (project-name-valkey_sessions) | `valkey/valkey:8-alpine` | Cluster 1 — sessions, MFA state |
| `valkey_cache` | (project-name-valkey_cache) | `valkey/valkey:8-alpine` | Cluster 2 — app cache |
| `valkey_queue` | (project-name-valkey_queue) | `valkey/valkey:8-alpine` | Cluster 3 — job queue |
| `valkey_auction` | (project-name-valkey_auction) | `valkey/valkey:8-alpine` | Cluster 4 — live auction bid state |
| `valkey_ratelimit` | (project-name-valkey_ratelimit) | `valkey/valkey:8-alpine` | Cluster 5 — per-user API rate limiting |
| `mailpit` | (project-name-mailpit) | `axllent/mailpit:latest` | Email capture and web UI |

---

## Port Mappings

| Service | Host port | Container port | Access |
|---|---|---|---|
| `app` | `80` (or `APP_PORT`) | `80` | http://localhost |
| `postgres` | `5432` (or `DB_PORT`) | `5432` | Direct psql access |
| `valkey_sessions` | `16379` (or `VALKEY_SESSIONS_HOST_PORT`) | `6379` | Valkey CLI |
| `valkey_cache` | `16380` (or `VALKEY_CACHE_HOST_PORT`) | `6379` | Valkey CLI |
| `valkey_queue` | `16381` (or `VALKEY_QUEUE_HOST_PORT`) | `6379` | Valkey CLI |
| `valkey_auction` | `16382` (or `VALKEY_AUCTION_HOST_PORT`) | `6379` | Valkey CLI |
| `valkey_ratelimit` | `16383` (or `VALKEY_RATELIMIT_HOST_PORT`) | `6379` | Valkey CLI |
| `mailpit` (web UI) | `8025` (or `MAILPIT_PORT`) | `8025` | http://localhost:8025 |
| `mailpit` (SMTP) | `1025` | `1025` | SMTP for dev mail |

### Why Valkey Ports Are 16379–16383

On Windows with WSL2, port `6379` is frequently bound by the host OS or the WSL2 network layer (sometimes a Redis process running in WSL2 automatically). Using `16379–16383` avoids this conflict without requiring any system changes. Inside the Docker bridge network, all Valkey containers still listen on standard `:6379`.

The `.env.example` configures the app to reach Valkey using the service names (`valkey_sessions`, `valkey_cache`, etc.) on port `6379` — the internal Docker network port, not the host-mapped port. The host-mapped ports are only used when connecting directly from your host machine (e.g., `make valkey-cache`).

---

## The App Container

### Build

Built from `Dockerfile.dev` at the project root. Installs:

- PHP 8.4-FPM
- Nginx (web server)
- Supervisor (process manager)
- PHP extensions: `pdo_pgsql`, `pgsql`, `gd`, `zip`, `bcmath`, `pcntl`, `intl`, `exif`, `opcache`
- phpredis PECL extension (Valkey-compatible)
- Composer
- Node.js 22 + npm (for Vite)
- PostgreSQL client (`psql`) for Makefile shortcuts

### What Supervisor Manages

Supervisor starts three programs when the container boots (defined in `docker/supervisor/supervisord.conf`):

| Program | Command | Purpose |
|---|---|---|
| `nginx` | `/usr/sbin/nginx -g "daemon off;"` | Serves HTTP requests on port 80 |
| `php-fpm` | `/usr/local/sbin/php-fpm --nodaemonize` | PHP process manager |
| `queue-worker` (×2) | `php artisan queue:work valkey --queue=priority,default --sleep=3 --tries=3 --max-time=3600` | Two queue worker processes |

The queue workers process `priority` first, then `default`. Both workers start automatically — no separate container needed for queue processing in dev.

### Volume Mount

The project directory is mounted into the container at `/var/www/html`:

```yaml
volumes:
  - .:/var/www/html
```

Any file you edit on your host is immediately reflected inside the container. No rebuild is needed for PHP, Blade, config, or migration changes. Rebuild is only needed for changes to `Dockerfile.dev`, Nginx config, PHP config, or Supervisor config.

### Entrypoint

`docker/entrypoint.sh` runs before Supervisor starts. It fixes file permissions that reset on Windows:

```bash
#!/bin/bash
set -e

# On Windows, Docker volume mounts reset ownership to root on container restart.
# PHP-FPM runs as www-data and needs write access to these directories.
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

exec "$@"
```

If you see `Permission denied` errors related to storage or bootstrap/cache, restart the container — the entrypoint will fix them.

---

## PostgreSQL — Single Server, 14 Databases

Unlike the old multi-container design, all 14 databases live on one `postgis/postgis:16-3.4` container. This simplifies dev setup and matches the production configuration (where all databases are on Azure Database for PostgreSQL Flexible Server).

### Why postgis/postgis Image?

DB 13 (geospatial) requires PostGIS extensions. The `postgis/postgis:16-3.4` image is PostgreSQL 16 with PostGIS 3.4 pre-installed. All other databases run on the same container without needing PostGIS — it's just available.

### Database Initialization

On first start, PostgreSQL runs all files in `/docker-entrypoint-initdb.d/`:

1. `docker/postgres/init.sql` — creates the three app users (`ah_app`, `ah_readonly`, `ah_etl`) and all 14 databases with correct ownership and grants
2. `docker/postgres/init-postgis.sh` — connects to `ah_geospatial` and enables the PostGIS extension

After initialization, you must run migrations:

```bash
make migrate-seed   # or: docker compose exec app php artisan migrate:all --fresh --seed
```

### Health Check

The `postgres` service has a Docker health check:

```yaml
healthcheck:
  test: ["CMD-SHELL", "pg_isready -U postgres -d postgres"]
  interval: 5s
  timeout: 5s
  retries: 10
```

The `app` service declares `depends_on: postgres: condition: service_healthy` — it will not start until PostgreSQL is ready. This prevents the app from starting before the database is available.

---

## Valkey Clusters

Five separate Valkey 8 containers, each isolated. A failure in one cluster does not affect others:

| Container | Persistence | Why |
|---|---|---|
| `valkey_sessions` | `--save 60 1` (RDB snapshot) | Sessions survive container restart |
| `valkey_cache` | `--save 60 1` | Cache survives restart (avoids cold-start hammering DB) |
| `valkey_queue` | `--save 60 1` | Jobs survive container restart — critical |
| `valkey_auction` | `--save 60 1` | Auction state survives restart |
| `valkey_ratelimit` | `--save 60 1` | Rate limit counters survive restart |

All Valkey containers have persistence enabled in dev (`--save 60 1` = write an RDB snapshot if at least 1 key changed in the last 60 seconds). This avoids losing queued jobs or sessions on `docker compose down && docker compose up`.

In production, the queue cluster uses AOF persistence for durability; the cache and ratelimit clusters may use no persistence (acceptable data loss on restart).

---

## Mailpit

Mailpit catches all outgoing email from the application. No email is actually sent — everything goes to the Mailpit inbox.

- **Web UI:** http://localhost:8025 — browse all captured emails
- **SMTP:** `localhost:1025` — Laravel sends mail here

The `.env.example` pre-configures `MAIL_HOST=mailpit` and `MAIL_PORT=1025` so this works out of the box.

---

## Makefile Commands

All make commands are shortcuts for the most common dev operations. Run `make help` to see all targets.

### Stack Management

```bash
make up          # Start all services (docker compose up -d)
make down        # Stop all services (docker compose down)
make restart     # down + up
make build       # Rebuild the app container image
make fresh       # Destroy volumes, rebuild, boot, migrate, seed — full reset
make logs        # Tail all service logs
make logs-app    # Tail app container logs only
```

`make fresh` is the nuclear option — it destroys all Docker volumes (database data, Valkey data) and rebuilds from scratch. Use when you want a completely clean state.

### Application

```bash
make shell       # Open a bash shell in the app container
make tinker      # Launch php artisan tinker
make artisan CMD="cache:clear"    # Run any artisan command
make composer CMD="require pkg"   # Run any Composer command
```

### Migrations

```bash
make migrate                    # php artisan migrate:all
make migrate-fresh              # php artisan migrate:all --fresh
make migrate-seed               # php artisan migrate:all --fresh --seed
make migrate-single DB=identity # php artisan migrate:single identity
```

### Cache

```bash
make flush-cache   # php artisan cache:clear (flushes Valkey Cluster 2 only)
                   # Does NOT flush sessions (Cluster 1) or queue (Cluster 3)
```

### psql Shortcuts

Each database has a direct psql shortcut:

```bash
make psql-identity        # psql -U ah_app -d ah_identity
make psql-property        # psql -U ah_app -d ah_property
make psql-lease           # psql -U ah_app -d ah_lease
make psql-billing         # psql -U ah_app -d ah_billing
make psql-wildlife        # psql -U ah_app -d ah_wildlife
make psql-commerce        # psql -U ah_app -d ah_commerce
make psql-communications  # psql -U ah_app -d ah_communications
make psql-analytics       # psql -U ah_etl -d ah_analytics
make psql-audit           # psql -U ah_app -d ah_audit
make psql-incidents       # psql -U ah_app -d ah_incidents
make psql-documents       # psql -U ah_app -d ah_documents
make psql-platform        # psql -U ah_app -d ah_platform
make psql-geospatial      # psql -U ah_app -d ah_geospatial
make psql-research        # psql -U ah_etl -d ah_research
```

### Valkey CLI Shortcuts

```bash
make valkey-sessions    # valkey-cli on cluster 1 (sessions)
make valkey-cache       # valkey-cli on cluster 2 (app cache)
make valkey-queue       # valkey-cli on cluster 3 (job queue)
make valkey-auction     # valkey-cli on cluster 4 (auction state)
make valkey-ratelimit   # valkey-cli on cluster 5 (rate limiting)
```

### Tests

```bash
make test           # php artisan test
make test-coverage  # php artisan test --coverage
```

---

## Networks

All services are on the `ah_network` bridge network. Containers reference each other by service name:

- The app connects to Postgres as `postgres:5432`
- The app connects to Valkey as `valkey_sessions:6379`, `valkey_cache:6379`, etc.
- These hostnames match the `.env.example` defaults (`DB_IDENTITY_HOST=postgres`, `VALKEY_SESSIONS_HOST=valkey_sessions`)

---

## Common Issues

**`Permission denied` on storage/ or bootstrap/cache/:** Restart the app container. The entrypoint will re-chown the directories.

**Port conflict on 16379–16383:** Another service is using one of these ports. Change the mapped host port in `.env` using `VALKEY_SESSIONS_HOST_PORT=26379` etc., then `make restart`.

**Port conflict on 5432:** Another PostgreSQL instance is running on the host. Change with `DB_PORT=15432` in `.env`.

**Migrations fail with "database does not exist":** `init.sql` may not have run yet. This happens if you start the stack without the init script or if PostgreSQL data volume already exists from a previous run with a different init. Run `make fresh` to destroy the volume and reinitialize.

**Queue jobs not processing:** Check Supervisor status inside the app container: `make shell` then `supervisorctl status`. The `queue-worker_00` and `queue-worker_01` programs should show `RUNNING`.

**Valkey connection refused:** A Valkey container is not running. Check `make logs` for errors. The Valkey containers have no health check dependency — the app starts even if they are down.
