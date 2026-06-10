# On-Premise Production — Docker Compose

## Overview

Production on-prem runs all services in Docker Compose on a single server
(or split across two servers for app/db separation). The app image is the
same image built by CI. All credentials come from a `.env.production` file
that is never committed to source control.

---

## Directory Layout on Server

```
/opt/american-headhunter/
├── docker-compose.prod.yml
├── .env.production              ← never in git
├── docker/
│   ├── postgres/
│   │   └── init/
│   │       └── 00_create_users.sql
│   ├── valkey/
│   │   ├── sessions.conf
│   │   ├── cache.conf
│   │   ├── queue.conf
│   │   ├── auction.conf
│   │   └── ratelimit.conf
│   ├── nginx/
│   │   └── ssl/                 ← Let's Encrypt certs via certbot
│   └── storage/
│       └── data/
├── backups/                     ← pg_dump outputs
└── logs/
```

---

## docker-compose.prod.yml

```yaml
services:

  # ──────────────────────────────────────────────────────────
  # Application
  # ──────────────────────────────────────────────────────────

  app:
    image: ${APP_IMAGE:-american-headhunter-app:latest}
    container_name: ah_app
    restart: unless-stopped
    env_file: .env.production
    depends_on:
      db-identity:
        condition: service_healthy
      db-property:
        condition: service_healthy
      db-lease:
        condition: service_healthy
      valkey-sessions:
        condition: service_started
    volumes:
      - app_storage:/var/www/html/storage/app
      - app_logs:/var/www/html/storage/logs
    ports:
      - "80:80"
      - "443:443"
    networks:
      - app_net
      - db_net
      - cache_net
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/healthz"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s

  # Reverse proxy + SSL termination (sits in front of app)
  caddy:
    image: caddy:2-alpine
    container_name: ah_caddy
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - app_net
    depends_on:
      - app

  # ──────────────────────────────────────────────────────────
  # PostgreSQL Databases — single instance, 14 databases
  # One container, 14 databases inside it
  # Ports only exposed on localhost — app accesses via Docker network
  # ──────────────────────────────────────────────────────────

  db-primary:
    image: postgis/postgis:16-3.4-alpine
    container_name: ah_db_primary
    restart: unless-stopped
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${DB_MASTER_PASSWORD}
      POSTGRES_DB: postgres
    volumes:
      - db_data:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d
      - ./backups:/backups
    networks:
      - db_net
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 10s
      timeout: 5s
      retries: 5
    # Do NOT expose port externally — only accessible via db_net
    # If you need external access for tooling: add a bastion/tunnel

  # ──────────────────────────────────────────────────────────
  # Valkey Clusters — 5 containers
  # ──────────────────────────────────────────────────────────

  valkey-sessions:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_sessions
    restart: unless-stopped
    command: valkey-server /etc/valkey/sessions.conf --requirepass ${VALKEY_SESSIONS_PASSWORD}
    volumes:
      - ./docker/valkey/sessions.conf:/etc/valkey/sessions.conf
      - valkey_sessions:/data
    networks:
      - cache_net

  valkey-cache:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_cache
    restart: unless-stopped
    command: valkey-server /etc/valkey/cache.conf --requirepass ${VALKEY_CACHE_PASSWORD}
    volumes:
      - ./docker/valkey/cache.conf:/etc/valkey/cache.conf
    networks:
      - cache_net
    # No volume — cache intentionally ephemeral

  valkey-queue:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_queue
    restart: unless-stopped
    command: valkey-server /etc/valkey/queue.conf --requirepass ${VALKEY_QUEUE_PASSWORD}
    volumes:
      - ./docker/valkey/queue.conf:/etc/valkey/queue.conf
      - valkey_queue:/data
    networks:
      - cache_net

  valkey-auction:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_auction
    restart: unless-stopped
    command: valkey-server /etc/valkey/auction.conf --requirepass ${VALKEY_AUCTION_PASSWORD}
    volumes:
      - ./docker/valkey/auction.conf:/etc/valkey/auction.conf
    networks:
      - cache_net

  valkey-ratelimit:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_ratelimit
    restart: unless-stopped
    command: valkey-server /etc/valkey/ratelimit.conf --requirepass ${VALKEY_RATELIMIT_PASSWORD}
    volumes:
      - ./docker/valkey/ratelimit.conf:/etc/valkey/ratelimit.conf
    networks:
      - cache_net

  # ──────────────────────────────────────────────────────────
  # Garage — S3-compatible Object Storage (replaces MinIO)
  # MinIO was archived Feb 2026 — Garage is the nonprofit-backed successor
  # See storage_strategy.md for full details
  # ──────────────────────────────────────────────────────────

  storage:
    image: dxflrs/garage:v1.0.1
    container_name: ah_storage
    restart: unless-stopped
    environment:
      GARAGE_ADMIN_TOKEN: ${GARAGE_ADMIN_TOKEN}
      GARAGE_RPC_SECRET: ${GARAGE_RPC_SECRET}
      GARAGE_METRICS_TOKEN: ${GARAGE_METRICS_TOKEN}
    volumes:
      - storage_data:/var/lib/garage/data
      - storage_meta:/var/lib/garage/meta
      - ./docker/garage/garage.toml:/etc/garage.toml:ro
    networks:
      - app_net
    # Port 3900: S3-compatible API (accessed by app internally via app_net)
    # Port 3903: Admin API (do NOT expose externally — SSH tunnel only)

  # ──────────────────────────────────────────────────────────
  # HashiCorp Vault — Azure Key Vault replacement for on-prem
  # Stores encryption keys and secrets
  # ──────────────────────────────────────────────────────────

  vault:
    image: hashicorp/vault:latest
    container_name: ah_vault
    restart: unless-stopped
    cap_add:
      - IPC_LOCK
    environment:
      VAULT_DEV_ROOT_TOKEN_ID: ${VAULT_DEV_TOKEN}
      VAULT_ADDR: http://0.0.0.0:8200
    volumes:
      - vault_data:/vault/data
      - ./docker/vault/config.hcl:/vault/config/config.hcl
    command: server -config=/vault/config/config.hcl
    networks:
      - app_net

  # ──────────────────────────────────────────────────────────
  # Monitoring
  # ──────────────────────────────────────────────────────────

  prometheus:
    image: prom/prometheus:latest
    container_name: ah_prometheus
    restart: unless-stopped
    volumes:
      - ./docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus
    networks:
      - app_net
      - db_net

  grafana:
    image: grafana/grafana:latest
    container_name: ah_grafana
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_PASSWORD}
      GF_SERVER_ROOT_URL: https://yourdomain.com/grafana/
    volumes:
      - grafana_data:/var/lib/grafana
    networks:
      - app_net
    depends_on:
      - prometheus

  postgres-exporter:
    image: prometheuscommunity/postgres-exporter:latest
    container_name: ah_pg_exporter
    restart: unless-stopped
    environment:
      DATA_SOURCE_NAME: postgresql://monitor_user:${DB_MONITOR_PASSWORD}@db-primary:5432/postgres?sslmode=disable
    networks:
      - db_net
      - app_net

# ──────────────────────────────────────────────────────────
# Networks — isolated by function
# ──────────────────────────────────────────────────────────

networks:
  app_net:
    driver: bridge
  db_net:
    driver: bridge
    internal: true    # DB network has no external internet access
  cache_net:
    driver: bridge
    internal: true    # Cache network has no external internet access

# ──────────────────────────────────────────────────────────
# Volumes
# ──────────────────────────────────────────────────────────

volumes:
  db_data:
  valkey_sessions:
  valkey_queue:
  storage_data:
  vault_data:
  prometheus_data:
  grafana_data:
  caddy_data:
  caddy_config:
  app_storage:
  app_logs:
```

---

## docker/postgres/init/01_create_databases.sql

Creates all 14 databases and users on first container start:

```sql
-- Create application users
CREATE ROLE app_user WITH LOGIN PASSWORD :'APP_USER_PASSWORD';
CREATE ROLE readonly_user WITH LOGIN PASSWORD :'READONLY_PASSWORD';
CREATE ROLE etl_writer WITH LOGIN PASSWORD :'ETL_PASSWORD';
CREATE ROLE audit_writer WITH LOGIN PASSWORD :'AUDIT_PASSWORD';
CREATE ROLE monitor_user WITH LOGIN PASSWORD :'MONITOR_PASSWORD';

-- Grant monitor user pg_monitor
GRANT pg_monitor TO monitor_user;

-- Create all 14 databases
CREATE DATABASE platform_identity;
CREATE DATABASE platform_property;
CREATE DATABASE platform_lease;
CREATE DATABASE platform_billing;
CREATE DATABASE platform_wildlife;
CREATE DATABASE platform_commerce;
CREATE DATABASE platform_communications;
CREATE DATABASE platform_analytics;
CREATE DATABASE platform_audit;
CREATE DATABASE platform_incidents;
CREATE DATABASE platform_documents;
CREATE DATABASE platform_config;
CREATE DATABASE platform_geospatial;
CREATE DATABASE platform_research;

-- Grant app_user access to all databases except research
\c platform_identity
GRANT ALL PRIVILEGES ON DATABASE platform_identity TO app_user;
GRANT CONNECT ON DATABASE platform_identity TO readonly_user;
GRANT CONNECT ON DATABASE platform_identity TO monitor_user;

-- (repeat pattern for property, lease, billing, wildlife,
--  commerce, communications, incidents, documents, config)

-- Analytics: readonly for app, write for etl_writer
\c platform_analytics
GRANT CONNECT ON DATABASE platform_analytics TO readonly_user;
GRANT CONNECT ON DATABASE platform_analytics TO etl_writer;
GRANT ALL PRIVILEGES ON DATABASE platform_analytics TO etl_writer;

-- Audit: INSERT only for audit_writer
\c platform_audit
GRANT CONNECT ON DATABASE platform_audit TO audit_writer;
GRANT CONNECT ON DATABASE platform_audit TO readonly_user;

-- Research: ETL writer only — no app_user
\c platform_research
GRANT ALL PRIVILEGES ON DATABASE platform_research TO etl_writer;
GRANT CONNECT ON DATABASE platform_research TO readonly_user;

-- Geospatial: needs PostGIS
\c platform_geospatial
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
GRANT ALL PRIVILEGES ON DATABASE platform_geospatial TO app_user;
```

---

## docker/caddy/Caddyfile

Caddy handles SSL automatically via Let's Encrypt:

```
yourdomain.com {
    reverse_proxy app:80

    # Garage admin API — restrict to admin IPs
    handle /storage-console/* {
        reverse_proxy storage:9001
        @not_admin {
            not remote_ip 192.168.1.0/24  # your office IP range
        }
        respond @not_admin 403
    }

    # Grafana — restrict to admin IPs
    handle /grafana/* {
        reverse_proxy grafana:3000
    }

    # Security headers
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        Referrer-Policy "strict-origin-when-cross-origin"
    }

    log {
        output file /var/log/caddy/access.log
    }
}
```

---

## Backup Strategy (On-Prem)

```bash
#!/bin/bash
# docker/scripts/backup.sh — run daily via cron

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR=/opt/american-headhunter/backups/$TIMESTAMP
mkdir -p $BACKUP_DIR

DATABASES=(
    "platform_identity"
    "platform_property"
    "platform_lease"
    "platform_billing"
    "platform_wildlife"
    "platform_commerce"
    "platform_communications"
    "platform_analytics"
    "platform_audit"
    "platform_incidents"
    "platform_documents"
    "platform_config"
    "platform_geospatial"
    "platform_research"
)

for DB in "${DATABASES[@]}"; do
    echo "Backing up $DB..."
    docker exec ah_db_primary pg_dump \
        -U postgres \
        --format=custom \
        --compress=9 \
        "$DB" > "$BACKUP_DIR/${DB}.dump"
done

# Compress all
tar -czf "/opt/american-headhunter/backups/backup_${TIMESTAMP}.tar.gz" -C "$BACKUP_DIR" .
rm -rf "$BACKUP_DIR"

# Keep last 30 days
find /opt/american-headhunter/backups/ -name "backup_*.tar.gz" -mtime +30 -delete

echo "Backup complete: backup_${TIMESTAMP}.tar.gz"

# Optional: sync to off-site (S3, Backblaze B2, or a second server)
# rclone copy /opt/american-headhunter/backups/ remote:american-headhunter-backups/
```

```bash
# Crontab entry — 3am daily
0 3 * * * /opt/american-headhunter/docker/scripts/backup.sh >> /var/log/ah-backup.log 2>&1
```
