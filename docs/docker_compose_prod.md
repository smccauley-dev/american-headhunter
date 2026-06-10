# docker-compose.prod.yml — On-Prem Production

This file runs on the production server(s). It differs from `docker-compose.yml` (local dev) in several ways:
- No exposed database ports to host (only internal network)
- No Garage admin port exposed
- Named volumes with explicit driver configs
- Restart policies on everything
- Resource limits
- Pulls from Azure Container Registry instead of building locally
- Reads from `.env.prod` instead of `.env`

---

## docker-compose.prod.yml

```yaml
name: american-headhunter

services:

  # ──────────────────────────────────────────────────────────
  # Application
  # ──────────────────────────────────────────────────────────

  app:
    image: ahregistry.azurecr.io/american-headhunter/app:${APP_VERSION:-latest}
    container_name: ah_app
    restart: unless-stopped
    command: ["web"]
    env_file: .env.prod
    depends_on:
      - db-identity
      - db-property
      - db-lease
      - db-billing
      - valkey-sessions
      - valkey-cache
    networks:
      - app_net
      - db_net
    deploy:
      resources:
        limits:
          memory: 1G
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/up"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

  worker:
    image: ahregistry.azurecr.io/american-headhunter/app:${APP_VERSION:-latest}
    container_name: ah_worker
    restart: unless-stopped
    command: ["worker"]
    env_file: .env.prod
    depends_on:
      - valkey-queue
    networks:
      - app_net
      - db_net
    deploy:
      resources:
        limits:
          memory: 512M

  worker-priority:
    image: ahregistry.azurecr.io/american-headhunter/app:${APP_VERSION:-latest}
    container_name: ah_worker_priority
    restart: unless-stopped
    command: ["worker-priority"]
    env_file: .env.prod
    depends_on:
      - valkey-queue
    networks:
      - app_net
      - db_net
    deploy:
      resources:
        limits:
          memory: 512M

  scheduler:
    image: ahregistry.azurecr.io/american-headhunter/app:${APP_VERSION:-latest}
    container_name: ah_scheduler
    restart: unless-stopped
    command: ["scheduler"]
    env_file: .env.prod
    networks:
      - app_net
      - db_net
    deploy:
      resources:
        limits:
          memory: 256M

  # ──────────────────────────────────────────────────────────
  # PostgreSQL Databases — 14 instances
  # All on db_net only — not exposed to host
  # ──────────────────────────────────────────────────────────

  db-identity:
    image: postgres:16-alpine
    container_name: ah_db_identity
    restart: unless-stopped
    env_file: .env.prod
    environment:
      POSTGRES_DB:       platform_identity
      POSTGRES_USER:     ${DB_IDENTITY_USERNAME}
      POSTGRES_PASSWORD: ${DB_IDENTITY_PASSWORD}
    volumes:
      - db_identity:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 4G
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_IDENTITY_USERNAME} -d platform_identity"]
      interval: 10s
      timeout: 5s
      retries: 5

  db-property:
    image: postgres:16-alpine
    container_name: ah_db_property
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_property
      POSTGRES_USER:     ${DB_PROPERTY_USERNAME}
      POSTGRES_PASSWORD: ${DB_PROPERTY_PASSWORD}
    volumes:
      - db_property:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
      - ./docker/postgres/conf/property.conf:/etc/postgresql/postgresql.conf:ro
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 8G

  db-lease:
    image: postgres:16-alpine
    container_name: ah_db_lease
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_lease
      POSTGRES_USER:     ${DB_LEASE_USERNAME}
      POSTGRES_PASSWORD: ${DB_LEASE_PASSWORD}
    volumes:
      - db_lease:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 4G

  db-billing:
    image: postgres:16-alpine
    container_name: ah_db_billing
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_billing
      POSTGRES_USER:     ${DB_BILLING_USERNAME}
      POSTGRES_PASSWORD: ${DB_BILLING_PASSWORD}
    volumes:
      - db_billing:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 4G

  db-wildlife:
    image: postgres:16-alpine
    container_name: ah_db_wildlife
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_wildlife
      POSTGRES_USER:     ${DB_WILDLIFE_USERNAME}
      POSTGRES_PASSWORD: ${DB_WILDLIFE_PASSWORD}
    volumes:
      - db_wildlife:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 8G

  db-commerce:
    image: postgres:16-alpine
    container_name: ah_db_commerce
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_commerce
      POSTGRES_USER:     ${DB_COMMERCE_USERNAME}
      POSTGRES_PASSWORD: ${DB_COMMERCE_PASSWORD}
    volumes:
      - db_commerce:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-communications:
    image: postgres:16-alpine
    container_name: ah_db_communications
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_communications
      POSTGRES_USER:     ${DB_COMMS_USERNAME}
      POSTGRES_PASSWORD: ${DB_COMMS_PASSWORD}
    volumes:
      - db_communications:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-analytics:
    image: postgres:16-alpine
    container_name: ah_db_analytics
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_analytics
      POSTGRES_USER:     ${DB_ANALYTICS_USERNAME}
      POSTGRES_PASSWORD: ${DB_ANALYTICS_PASSWORD}
    volumes:
      - db_analytics:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-audit:
    image: postgres:16-alpine
    container_name: ah_db_audit
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_audit
      POSTGRES_USER:     ${DB_AUDIT_USERNAME}
      POSTGRES_PASSWORD: ${DB_AUDIT_PASSWORD}
    volumes:
      - db_audit:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-incidents:
    image: postgres:16-alpine
    container_name: ah_db_incidents
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_incidents
      POSTGRES_USER:     ${DB_INCIDENTS_USERNAME}
      POSTGRES_PASSWORD: ${DB_INCIDENTS_PASSWORD}
    volumes:
      - db_incidents:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-documents:
    image: postgres:16-alpine
    container_name: ah_db_documents
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_documents
      POSTGRES_USER:     ${DB_DOCUMENTS_USERNAME}
      POSTGRES_PASSWORD: ${DB_DOCUMENTS_PASSWORD}
    volumes:
      - db_documents:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-platform:
    image: postgres:16-alpine
    container_name: ah_db_platform
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_config
      POSTGRES_USER:     ${DB_PLATFORM_USERNAME}
      POSTGRES_PASSWORD: ${DB_PLATFORM_PASSWORD}
    volumes:
      - db_platform:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  db-geospatial:
    image: postgis/postgis:16-3.4-alpine
    container_name: ah_db_geospatial
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_geospatial
      POSTGRES_USER:     ${DB_GEO_USERNAME}
      POSTGRES_PASSWORD: ${DB_GEO_PASSWORD}
    volumes:
      - db_geospatial:/var/lib/postgresql/data
    networks:
      - db_net
    deploy:
      resources:
        limits:
          memory: 8G

  db-research:
    image: postgres:16-alpine
    container_name: ah_db_research
    restart: unless-stopped
    environment:
      POSTGRES_DB:       platform_research
      POSTGRES_USER:     ${DB_RESEARCH_USERNAME}
      POSTGRES_PASSWORD: ${DB_RESEARCH_PASSWORD}
    volumes:
      - db_research:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    networks:
      - db_net

  # ──────────────────────────────────────────────────────────
  # Valkey Clusters — 5 instances
  # ──────────────────────────────────────────────────────────

  valkey-sessions:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_sessions
    restart: unless-stopped
    command: valkey-server --save 60 1 --requirepass ${VALKEY_SESSIONS_PASSWORD}
    volumes:
      - valkey_sessions:/data
    networks:
      - app_net

  valkey-cache:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_cache
    restart: unless-stopped
    command: valkey-server --save "" --requirepass ${VALKEY_CACHE_PASSWORD}
    networks:
      - app_net

  valkey-queue:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_queue
    restart: unless-stopped
    command: valkey-server --save 30 1 --requirepass ${VALKEY_QUEUE_PASSWORD}
    volumes:
      - valkey_queue:/data
    networks:
      - app_net

  valkey-auction:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_auction
    restart: unless-stopped
    command: valkey-server --save "" --requirepass ${VALKEY_AUCTION_PASSWORD}
    networks:
      - app_net

  valkey-ratelimit:
    image: valkey/valkey:8-alpine
    container_name: ah_valkey_ratelimit
    restart: unless-stopped
    command: valkey-server --save "" --requirepass ${VALKEY_RATELIMIT_PASSWORD}
    networks:
      - app_net

  # ──────────────────────────────────────────────────────────
  # Garage — S3-compatible Object Storage (replaces MinIO; Azure Blob replacement on-prem)
  # MinIO was archived Feb 2026 — Garage is the nonprofit-backed successor
  # See storage_strategy.md for full details and Azure migration path
  # ──────────────────────────────────────────────────────────

  storage:
    image: dxflrs/garage:v1.0.1
    container_name: ah_storage
    restart: unless-stopped
    environment:
      GARAGE_RPC_SECRET: ${GARAGE_RPC_SECRET}
      GARAGE_ADMIN_TOKEN: ${GARAGE_ADMIN_TOKEN}
      GARAGE_METRICS_TOKEN: ${GARAGE_METRICS_TOKEN}
    volumes:
      - storage_data:/var/lib/garage/data
      - storage_meta:/var/lib/garage/meta
      - ./docker/garage/garage.toml:/etc/garage.toml:ro
    networks:
      - app_net
    deploy:
      resources:
        limits:
          memory: 4G
    healthcheck:
      test: ["CMD", "/garage", "status"]
      interval: 30s
      timeout: 10s
      retries: 3
    # S3 API port 3900 — internal only
    # Admin port 3903 — internal only, access via SSH tunnel

  # ──────────────────────────────────────────────────────────
  # Vault — Secrets Management (Azure Key Vault replacement on-prem)
  # ──────────────────────────────────────────────────────────

  vault:
    image: hashicorp/vault:latest
    container_name: ah_vault
    restart: unless-stopped
    cap_add:
      - IPC_LOCK
    environment:
      VAULT_ADDR: http://0.0.0.0:8200
      VAULT_API_ADDR: http://vault:8200
    command: server -config=/vault/config/vault.hcl
    volumes:
      - vault_data:/vault/data
      - ./docker/vault/vault.hcl:/vault/config/vault.hcl:ro
    networks:
      - app_net

# ──────────────────────────────────────────────────────────
# Networks
# app_net: app containers + Valkey + Garage + Vault
# db_net:  app containers + all PostgreSQL containers only
# ──────────────────────────────────────────────────────────

networks:
  app_net:
    driver: bridge
    internal: false
  db_net:
    driver: bridge
    internal: true   # PostgreSQL never reachable from outside Docker

# ──────────────────────────────────────────────────────────
# Volumes — named, persist across container restarts
# ──────────────────────────────────────────────────────────

volumes:
  db_identity:
  db_property:
  db_lease:
  db_billing:
  db_wildlife:
  db_commerce:
  db_communications:
  db_analytics:
  db_audit:
  db_incidents:
  db_documents:
  db_platform:
  db_geospatial:
  db_research:
  valkey_sessions:
  valkey_queue:
  storage_data:
  storage_meta:
  vault_data:
```

---

## Host Nginx — SSL Termination

Nginx runs on the host (not in a container) and proxies HTTPS traffic to the app container on port 80.

```nginx
# /etc/nginx/sites-available/americanheadhunter.com

server {
    listen 80;
    server_name americanheadhunter.com www.americanheadhunter.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name americanheadhunter.com www.americanheadhunter.com;

    ssl_certificate     /etc/letsencrypt/live/americanheadhunter.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/americanheadhunter.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;

    location / {
        proxy_pass         http://127.0.0.1:80;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
```

```bash
# Install cert and auto-renew
certbot --nginx -d americanheadhunter.com -d www.americanheadhunter.com
```

---

## docker/vault/vault.hcl

```hcl
storage "file" {
  path = "/vault/data"
}

listener "tcp" {
  address     = "0.0.0.0:8200"
  tls_disable = true   # TLS handled at network boundary — internal only
}

api_addr = "http://vault:8200"
ui       = false
log_level = "warn"
```
