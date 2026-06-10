# Azure Migration Guide

## When to Migrate

Trigger points that make Azure worth the cost jump:

- Monthly revenue justifies $2,500–4,000/month infrastructure cost
- You need SOC 2 compliance documentation
- A large enterprise client requires cloud-hosted infrastructure
- Hardware needs upgrading and Azure capex vs opex makes sense
- You need geographic redundancy or multi-region

---

## Migration Order — Least Risky First

Migrate one service at a time. The app keeps running throughout.
Each step is a single `.env` change + container restart.

```
Step 1  Blob Storage      Garage → Azure Blob          (zero downtime)
Step 2  Secrets           HashiCorp Vault → Key Vault (zero downtime)
Step 3  Databases         Local PG → Azure PG         (short maintenance window per DB)
Step 4  Valkey            Local → Azure Cache          (zero downtime)
Step 5  App Container     On-prem → Azure Container Apps (DNS cutover)
```

---

## Step 1 — Blob Storage: Garage → Azure Blob

### Create Azure resources
```bash
az group create --name american-headhunter-prod --location eastus2

az storage account create \
  --name americanheadhunter \
  --resource-group american-headhunter-prod \
  --location eastus2 \
  --sku Standard_LRS \
  --kind StorageV2

# Create containers
for container in platform-properties platform-documents platform-harvests platform-backups; do
    az storage container create \
      --name $container \
      --account-name americanheadhunter
done
```

### Migrate existing files from Garage
```bash
# Install rclone
curl https://rclone.org/install.sh | sudo bash

# Configure rclone for both Garage (source) and Azure Blob (dest)
rclone config  # set up 'storage' and 'azure' remotes

# Copy all buckets
rclone copy storage:platform-properties azure:platform-properties --progress
rclone copy storage:platform-documents azure:platform-documents --progress
rclone copy storage:platform-harvests azure:platform-harvests --progress
```

### Update .env — swap storage driver
```bash
# Before (Garage on-prem)
STORAGE_DRIVER=s3
AWS_ENDPOINT=http://your-server:9000
STORAGE_ACCESS_KEY=${GARAGE_ACCESS_KEY}
STORAGE_SECRET_KEY=${GARAGE_SECRET_KEY}

# After (Azure Blob) — application code unchanged
STORAGE_DRIVER=azure
AZURE_STORAGE_ACCOUNT=americanheadhunter
AZURE_STORAGE_KEY=<key-from-portal>
AZURE_STORAGE_CONTAINER_PROPERTIES=platform-properties
AZURE_STORAGE_CONTAINER_DOCUMENTS=platform-documents
AZURE_STORAGE_CONTAINER_HARVESTS=platform-harvests
```

```bash
# Restart app container — no downtime
docker compose -f docker-compose.prod.yml up -d app
```

**Garage container can now be stopped.** Blob migration complete.

> **Database migration required — see DEPLOYMENT.md §3.**
> The `documents` table has a `CHECK (storage_provider IN ('garage', 'azure_blob'))` constraint.
> When you flip the storage backend to Azure Blob, you must also run a data migration that
> updates existing rows from `storage_provider = 'garage'` to `'azure_blob'`, and change
> the column DEFAULT. Do this before decommissioning Garage, or the app will attempt to
> serve existing files from a stopped storage endpoint. The pre-migration check and
> re-encryption pattern are documented in DEPLOYMENT.md under "storage_provider".

---

## Step 2 — Secrets: HashiCorp Vault → Azure Key Vault

### Create Azure Key Vault
```bash
az keyvault create \
  --name american-headhunter-vault \
  --resource-group american-headhunter-prod \
  --location eastus2 \
  --enable-soft-delete true \
  --retention-days 90

# Import all 14 encryption keys
for key in identity property lease billing wildlife commerce communications \
           analytics audit incidents documents platform geospatial research; do
    az keyvault secret set \
      --vault-name american-headhunter-vault \
      --name "enc-key-$key" \
      --value "$(docker exec ah_vault vault kv get -field=value secret/enc_key_$key)"
done
```

### Update .env
```bash
# Before (HashiCorp Vault)
VAULT_DRIVER=hashicorp
VAULT_ADDR=http://vault:8200
VAULT_TOKEN=your-token

# After (Azure Key Vault)
VAULT_DRIVER=azure
AZURE_KEY_VAULT_URL=https://american-headhunter-vault.vault.azure.net/
AZURE_KEY_VAULT_TENANT_ID=your-tenant-id
AZURE_KEY_VAULT_CLIENT_ID=your-client-id
AZURE_KEY_VAULT_CLIENT_SECRET=your-client-secret
```

```bash
docker compose -f docker-compose.prod.yml up -d app
```

---

## Step 3 — Databases: Local PostgreSQL → Azure PostgreSQL

This is the only step with a brief maintenance window (5–15 min per database).
Migrate non-critical databases first to practice. Do billing and identity last.

### Recommended migration order
```
1. platform_analytics      (read-heavy, ETL — easiest)
2. platform_research       (ETL only)
3. platform_communications (tolerate brief gap)
4. platform_commerce       (auctions can pause)
5. platform_wildlife       (field ops — seasonal tolerance)
6. platform_incidents      (low write volume)
7. platform_documents      (low write volume)
8. platform_geospatial     (read-heavy)
9. platform_config         (heavily cached)
10. platform_lease         (important — short window)
11. platform_property      (important — short window)
12. platform_billing       (critical — do last, shortest window)
13. platform_audit         (append-only — safe anytime)
14. platform_identity      (critical — do last)
```

### For each database — the migration procedure

```bash
# Example: migrating platform_wildlife

# 1. Create Azure PostgreSQL Flexible Server (one server can host all 14 databases)
az postgres flexible-server create \
  --name american-headhunter-db \
  --resource-group american-headhunter-prod \
  --location eastus2 \
  --admin-user pgadmin \
  --admin-password <strong-password> \
  --sku-name Standard_D4s_v3 \
  --storage-size 256 \
  --version 16

# Enable pgvector extension (needed for PostGIS later)
az postgres flexible-server parameter set \
  --resource-group american-headhunter-prod \
  --server-name american-headhunter-db \
  --name azure.extensions \
  --value POSTGIS,UUID-OSSP,PGCRYPTO,CITEXT

# 2. Create the database on Azure
az postgres flexible-server db create \
  --resource-group american-headhunter-prod \
  --server-name american-headhunter-db \
  --database-name platform_wildlife

# 3. Dump from on-prem (while app is still running — consistent snapshot)
docker exec ah_db_primary pg_dump \
  -U postgres \
  --format=custom \
  platform_wildlife > /tmp/wildlife_migration.dump

# 4. Restore to Azure
pg_restore \
  -h american-headhunter-db.postgres.database.azure.com \
  -U pgadmin \
  -d platform_wildlife \
  --no-owner \
  --no-acl \
  /tmp/wildlife_migration.dump

# 5. Verify row counts match
docker exec ah_db_primary psql -U postgres -d platform_wildlife \
  -c "SELECT tablename, n_live_tup FROM pg_stat_user_tables ORDER BY tablename;"

psql "host=american-headhunter-db.postgres.database.azure.com user=pgadmin dbname=platform_wildlife sslmode=require" \
  -c "SELECT tablename, n_live_tup FROM pg_stat_user_tables ORDER BY tablename;"

# 6. Maintenance window — update .env and restart app
# Before:
DB_WILDLIFE_HOST=db-primary
DB_WILDLIFE_PORT=5432
DB_WILDLIFE_SSLMODE=disable

# After:
DB_WILDLIFE_HOST=american-headhunter-db.postgres.database.azure.com
DB_WILDLIFE_PORT=5432
DB_WILDLIFE_SSLMODE=require

# 7. Restart app — maintenance window ends
docker compose -f docker-compose.prod.yml up -d app

# 8. Verify app is healthy
curl https://yourdomain.com/healthz
```

Repeat for all 14 databases. After all are migrated, stop the on-prem PostgreSQL container.

---

## Step 4 — Valkey: Local → Azure Cache for Redis

Azure Cache for Redis is Valkey-compatible.

```bash
# Create 5 Azure Cache instances (Basic C1 for non-critical, Standard C2 for sessions/queue)
for cluster in sessions cache queue auction ratelimit; do
    az redis create \
      --name "american-headhunter-$cluster" \
      --resource-group american-headhunter-prod \
      --location eastus2 \
      --sku Standard \
      --vm-size c1
done

# Get connection strings
az redis show-access-keys \
  --name american-headhunter-sessions \
  --resource-group american-headhunter-prod
```

Update `.env` for each cluster — no data migration needed (Valkey is ephemeral by design):

```bash
# Before
VALKEY_SESSIONS_HOST=valkey-sessions
VALKEY_SESSIONS_PORT=6379
VALKEY_SESSIONS_PASSWORD=local-password

# After
VALKEY_SESSIONS_HOST=american-headhunter-sessions.redis.cache.windows.net
VALKEY_SESSIONS_PORT=6380
VALKEY_SESSIONS_PASSWORD=<key-from-azure>
# Note: Azure Cache uses SSL on port 6380
```

**Important:** When you swap the sessions cluster, all active users are logged out. Do this during low-traffic hours. Announce it in advance if needed.

```bash
docker compose -f docker-compose.prod.yml up -d app
```

---

## Step 5 — App: On-Prem Container → Azure Container Apps

### Build and push image to Azure Container Registry

```bash
# Create registry
az acr create \
  --name ahregistry \
  --resource-group american-headhunter-prod \
  --sku Standard \
  --admin-enabled true

# Build and push
az acr login --name ahregistry

docker build -t ahregistry.azurecr.io/american-headhunter-app:latest .
docker push ahregistry.azurecr.io/american-headhunter-app:latest
```

### Create Container Apps environment

```bash
az containerapp env create \
  --name american-headhunter-env \
  --resource-group american-headhunter-prod \
  --location eastus2

# Create the app
az containerapp create \
  --name american-headhunter-app \
  --resource-group american-headhunter-prod \
  --environment american-headhunter-env \
  --image ahregistry.azurecr.io/american-headhunter-app:latest \
  --target-port 80 \
  --ingress external \
  --min-replicas 2 \
  --max-replicas 10 \
  --cpu 2.0 \
  --memory 4.0Gi \
  --env-vars-file .env.production
```

### DNS Cutover

```bash
# Get Azure Container Apps URL
az containerapp show \
  --name american-headhunter-app \
  --resource-group american-headhunter-prod \
  --query properties.configuration.ingress.fqdn

# Update Cloudflare DNS:
# yourdomain.com CNAME → american-headhunter-app.azurecontainerapps.io
# TTL: 60 seconds during cutover, extend after confirmed healthy
```

**At this point the on-prem server can be powered down or repurposed.**

---

## Post-Migration Checklist

```
□ All 14 database connections verified healthy
□ All 5 Valkey connections verified healthy
□ File uploads routing to Azure Blob
□ E-signatures working (Dropbox Sign webhooks hitting new URL)
□ Stripe webhooks updated to new domain/URL
□ Checkr webhooks updated
□ Email sending healthy (check Mailgun logs)
□ Queue workers processing jobs (check Grafana queue depth)
□ Scheduler running (check artisan schedule:run logs)
□ SSL certificate valid and auto-renewing
□ Monitoring alerts configured
□ Backup jobs updated to dump from Azure PostgreSQL
□ On-prem server secured or decommissioned
```

---

## Cost at Each Migration Stage

| Stage | Monthly Infra Cost |
|---|---|
| Full on-prem (Docker Compose) | $100–200 (power + internet) |
| After Step 1 (Azure Blob added) | +$30–100/month |
| After Step 2 (Key Vault added) | +$15/month |
| After Step 3 (Azure PG × 14) | +$1,200–2,500/month |
| After Step 4 (Azure Cache × 5) | +$350/month |
| After Step 5 (Container Apps) | +$200–400/month |
| **Full Azure** | **~$2,000–3,500/month** |

You control the pace. Each step is independent. You can do Step 1 and 2
(storage + secrets) immediately for better durability without the big
database cost jump. Move databases when revenue supports it.
