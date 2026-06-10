# Storage Strategy

American Headhunter uses **S3-compatible object storage** abstracted through Laravel's filesystem layer. The storage backend is interchangeable — the application code never knows or cares which S3-compatible service is behind the interface.

This is deliberate. The backend changes as the infrastructure evolves, without application code changes:

| Stage | Storage backend | Why |
|---|---|---|
| **Local development** | Garage (Docker container) | Byte-for-byte parity with production |
| **Stage 1 production (on-prem)** | **Garage** on a VMware VM | Fast local network, internet-independent file operations |
| **Stage 2 production (hybrid)** | Garage + Azure Blob (hot/cold split) | Optional — only if archive size justifies it |
| **Stage 3 production (Azure)** | Azure Blob Storage | Native integration, infinite scale, zero ops |

---

## Why Garage, Not MinIO

**MinIO is dead as of February 12, 2026.** The project was archived, development ceased, and the company pivoted all engineering to AIStor (their paid commercial product). Any current deployment using MinIO is on unsupported software with no security updates path.

**Garage** (from the Deuxfleurs nonprofit) is the community-consensus replacement:

- Fully S3-compatible — Laravel's Flysystem S3 driver works unchanged
- Lightweight — one VM with 8GB RAM handles it
- Not-for-profit governance — no commercial bait-and-switch risk
- Native replication across nodes for HA when needed
- Handles the realistic scale of AH (multi-TB, millions of objects) well

Other alternatives considered:
- **SeaweedFS** — more features, more operational complexity; overkill for AH scale
- **Ceph** — industrial-grade but needs 3+ nodes and dedicated expertise
- **Azure Blob from day 1** — rejected due to internet dependency for every file operation (see below)
- **Backblaze B2** — cheap, but still internet-dependent with same issue as Azure Blob

---

## Why On-Prem Storage, Not Cloud-from-Day-One

The core use cases for AH involve file operations over **marginal internet connections:**

- Landowners uploading property photos during signup
- Hunters syncing trail cam photos from rural properties
- Harvest photo uploads from the field on flaky cellular
- PDF lease documents generated and displayed during signing flows
- Map tile operations during property browsing

Every one of those operations hitting Azure Blob over the public internet introduces latency, timeouts, and failure modes that don't exist when storage is on the local network.

**Server-to-storage stays on local gigabit network with Garage.**

Your on-prem VMware infrastructure has abundant local bandwidth. Moving file operations off the local network to the public internet would be a step backward for user experience, especially for the field-use scenarios that are core to the product.

**When cloud storage wins:** Once you migrate the entire platform to Azure, storage being in Azure is the correct choice because compute is also in Azure. Local-network bandwidth between compute and storage is preserved — it's just Azure's network instead of your VMware network.

---

## Architecture — On-Prem Stage 1

### Garage VM

| Attribute | Value |
|---|---|
| OS | Ubuntu 24.04 LTS |
| vCPU | 2 |
| RAM | 8GB |
| Storage | 2TB NVMe SSD (expandable) |
| Network | On production VLAN |
| Role | S3-compatible object storage |

Runs a single Garage container in production. HA-replicated setup (3 nodes) is available if needed, but single-node is appropriate for Stage 1.

### Docker Compose Service

```yaml
# Replaces the MinIO service in docker-compose.prod.yml

storage:
  image: dxflrs/garage:v1.0.1
  container_name: ah_storage
  restart: unless-stopped
  environment:
    GARAGE_RPC_SECRET_FILE: /run/secrets/garage_rpc_secret
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
  # API port (S3-compatible) - 3900
  # Admin port - 3903 (internal only)
  healthcheck:
    test: ["CMD", "/garage", "status"]
    interval: 30s
    timeout: 10s
    retries: 3

volumes:
  storage_data:
  storage_meta:
```

### Garage Configuration

```toml
# docker/garage/garage.toml

metadata_dir = "/var/lib/garage/meta"
data_dir = "/var/lib/garage/data"
db_engine = "lmdb"

replication_factor = 1  # Stage 1 single-node; set to 3 for HA cluster

rpc_bind_addr = "[::]:3901"
rpc_public_addr = "ah_storage:3901"

[s3_api]
s3_region = "us-east-1"
api_bind_addr = "[::]:3900"
root_domain = ".storage.internal"

[s3_web]
bind_addr = "[::]:3902"
root_domain = ".web.internal"
index = "index.html"

[admin]
api_bind_addr = "[::]:3903"
admin_token = "${GARAGE_ADMIN_TOKEN}"
metrics_token = "${GARAGE_METRICS_TOKEN}"
```

### Bucket Structure

One Garage instance, multiple buckets — one per content domain matching the cross-DB rule:

| Bucket | Purpose | DB reference |
|---|---|---|
| `ah-property-photos` | Landowner-uploaded property photos | DB 2 Property + DB 11 Documents |
| `ah-property-videos` | Walkthrough videos, drone footage | DB 2 Property + DB 11 Documents |
| `ah-trail-cams` | Trail camera photos synced from devices | DB 5 Wildlife + DB 11 Documents |
| `ah-harvest-photos` | User-uploaded harvest photos | DB 5 Wildlife + DB 11 Documents |
| `ah-documents` | Leases, contracts, W-9s, insurance certs | DB 3 Lease + DB 4 Billing + DB 11 Documents |
| `ah-user-avatars` | Profile pictures | DB 1 Identity + DB 11 Documents |
| `ah-club-assets` | Club logos, bylaws PDFs | DB 3 Lease + DB 11 Documents |
| `ah-generated` | System-generated PDFs (invoices, reports) | DB 4 Billing + DB 11 Documents |
| `ah-audit-exports` | Scheduled audit log exports | DB 9 Audit |
| `ah-backups` | Database backup targets | All |

Metadata for every object lives in DB 11 `documents` table. Garage stores the bytes; PostgreSQL stores the reference.

### Laravel Configuration

```php
// config/filesystems.php

'disks' => [
    'storage' => [
        'driver' => 's3',
        'key'     => env('STORAGE_ACCESS_KEY'),
        'secret'  => env('STORAGE_SECRET_KEY'),
        'region'  => env('STORAGE_REGION', 'us-east-1'),
        'bucket'  => env('STORAGE_BUCKET'),
        'endpoint' => env('STORAGE_ENDPOINT'),  // http://ah_storage:3900 on-prem
        'use_path_style_endpoint' => true,       // Required for Garage
        'visibility' => 'private',
        'throw' => true,
    ],
],

'default' => env('FILESYSTEM_DISK', 'storage'),
```

### Environment Variables

```bash
# On-prem production (.env.prod)
FILESYSTEM_DISK=storage
STORAGE_ENDPOINT=http://ah_storage:3900
STORAGE_ACCESS_KEY=${GARAGE_ACCESS_KEY}
STORAGE_SECRET_KEY=${GARAGE_SECRET_KEY}
STORAGE_REGION=us-east-1
STORAGE_BUCKET=ah-property-photos  # Default bucket; services override

# When migrating to Azure (future state)
FILESYSTEM_DISK=storage
STORAGE_ENDPOINT=https://ahstorage.blob.core.windows.net
STORAGE_ACCESS_KEY=${AZURE_STORAGE_ACCOUNT}
STORAGE_SECRET_KEY=${AZURE_STORAGE_KEY}
STORAGE_REGION=eastus
STORAGE_BUCKET=ah-property-photos
```

The application code doesn't change. Only env vars.

---

## Migration Path to Azure

### When to migrate

Migrate storage to Azure when **any** of the following is true:

- Application compute is moving to Azure (storage should follow compute)
- Storage needs exceed what local infrastructure can reasonably provide (>50TB)
- Multi-region redundancy becomes a requirement
- Your team wants to eliminate storage operational overhead

### Migration steps

**Step 1 — Provision Azure Blob Storage**

```bash
az storage account create \
  --name ahstorage \
  --resource-group american-headhunter-rg \
  --location eastus \
  --sku Standard_ZRS \
  --kind StorageV2 \
  --access-tier Hot \
  --https-only true

# Create containers matching Garage bucket names
for bucket in ah-property-photos ah-property-videos ah-trail-cams \
              ah-harvest-photos ah-documents ah-user-avatars \
              ah-club-assets ah-generated ah-audit-exports; do
  az storage container create \
    --name "$bucket" \
    --account-name ahstorage
done
```

**Step 2 — Sync data with rclone**

```bash
# Install rclone on the Garage VM
curl https://rclone.org/install.sh | sudo bash

# Configure both endpoints
rclone config  # Create "garage" and "azure" remotes

# Sync each bucket (run in parallel for speed)
for bucket in ah-property-photos ah-property-videos ah-trail-cams \
              ah-harvest-photos ah-documents ah-user-avatars \
              ah-club-assets ah-generated; do
  rclone sync garage:$bucket azure:$bucket \
    --transfers 32 \
    --checkers 64 \
    --progress &
done
wait
```

**Step 3 — Incremental sync while app still writes to Garage**

```bash
# Run incremental sync every 5 minutes to keep Azure caught up
while true; do
  for bucket in ah-property-photos ah-trail-cams ah-harvest-photos; do
    rclone sync garage:$bucket azure:$bucket --fast-list
  done
  sleep 300
done
```

**Step 4 — Cutover window (brief maintenance)**

1. Put app in read-only mode (no new uploads)
2. Run final incremental sync
3. Verify bucket contents match (compare object counts)
4. Update environment variables on all app containers
5. Restart app containers
6. Re-enable writes
7. Monitor for 48 hours
8. Decommission Garage VM

Total cutover downtime: typically 5-15 minutes depending on last-sync delta.

**Step 5 — Lifecycle policies**

Once on Azure, configure automatic tiering:

```bash
az storage account management-policy create \
  --account-name ahstorage \
  --resource-group american-headhunter-rg \
  --policy @lifecycle-policy.json
```

Where `lifecycle-policy.json` defines rules like "move objects not accessed in 90 days to Cool tier, not accessed in 365 days to Archive tier" — significant cost savings on older content like expired lease photos and archived trail cam footage.

---

## Development Environment

Local dev uses Garage in Docker Compose, identical to production:

```yaml
# docker-compose.yml (local dev)

storage:
  image: dxflrs/garage:v1.0.1
  container_name: ah_dev_storage
  ports:
    - "3900:3900"     # S3 API — exposed for local testing
    - "3903:3903"     # Admin — exposed for local testing
  volumes:
    - ./docker/garage/garage-dev.toml:/etc/garage.toml:ro
    - garage_dev_data:/var/lib/garage
```

Developers get a local storage endpoint at `http://localhost:3900` that matches production's API behavior exactly.

---

## Optional: Hybrid Hot/Cold Split (Stage 2)

If the storage footprint grows large and you're not ready to fully migrate to Azure, a hybrid approach is possible:

- **Garage on-prem** keeps hot data (current season, active leases, recent uploads)
- **Azure Blob Cool or Archive tier** stores cold data (previous seasons, expired leases)
- **Lifecycle job** moves objects from Garage to Azure Blob after 90 days of no access
- **Restoration job** pulls objects back from Azure if accessed (transparent to users)

This is implemented as a Laravel job (`ArchiveOldStorageObjects`) and adds a "cold storage" flag to DB 11 `documents.storage_tier` column. The app automatically fetches from the right location based on the tier.

**Only worth implementing when:**
- Storage footprint exceeds 20TB
- Access patterns are clearly bimodal (new stuff hot, old stuff cold)
- You're not planning to fully migrate to Azure within 12 months

Most projects skip this and go directly from Stage 1 (full on-prem) to Stage 3 (full Azure).

---

## Backup Strategy

### Garage on-prem

- **Nightly:** `rclone sync garage:ah-* /mnt/backup/daily/{date}/` to local backup NAS
- **Weekly:** Same target, kept for 4 weeks
- **Monthly:** Offsite sync to Backblaze B2 (separate from primary, cheap, geographically separated)
- **VMware snapshots:** Weekly snapshot of the Garage VM for fast recovery

### Azure Blob

- **Built-in:** Zone-redundant storage (ZRS) replicates across 3 availability zones in region
- **Geo-redundancy:** Upgrade to GZRS for cross-region replication
- **Point-in-time restore:** Enabled on containers with versioning + soft delete
- **Backup vault:** Azure Backup for additional off-region copies

Backup strategy adapts automatically when you migrate — Azure's built-in redundancy removes most of the self-managed complexity.

---

## Cost Comparison

**On-prem Garage:**
- Hardware: Uses existing VMware capacity — marginal cost ~$0
- Electricity: ~$15/month for the VM
- Internet bandwidth: No egress costs
- Monthly total: **~$15**

**Azure Blob (when fully migrated):**
- Storage: $0.0184/GB hot tier
- At 1TB: ~$20/month
- At 10TB: ~$200/month
- Egress to internet: $0.087/GB (~$87 per TB of downloads)
- Monthly total at MVP scale: **~$50-200**

**On-prem is significantly cheaper** as long as you have the local infrastructure. Azure becomes competitive when you also migrate compute (eliminates the need for VMware hosts for the app tier).

---

## Summary

| Question | Answer |
|---|---|
| Storage backend Stage 1? | Garage on-prem |
| Migration path? | Azure Blob when app compute moves to Azure |
| Application changes needed on migration? | None — environment variables only |
| Dev environment match? | Garage in Docker Compose locally |
| Backup approach? | Rclone to local NAS nightly, B2 offsite monthly |
| Hybrid option? | Available in Stage 2 if needed, most projects skip it |
| What replaced MinIO? | Garage — architecturally similar, nonprofit-backed, currently active |
