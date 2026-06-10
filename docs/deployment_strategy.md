# Deployment Strategy — On-Prem Now, Azure When Ready

## The Core Principle

**The application never knows where it's running.** All infrastructure differences live in environment variables and orchestration config — not in code. The same Docker image that runs on a Hetzner server today runs in Azure Container Apps tomorrow without a single line of application code changing.

---

## The Three Stages

```
Stage 1 (Now)                Stage 2 (Growth)             Stage 3 (Scale)
────────────────────         ────────────────────         ────────────────────
Single on-prem server        On-prem + Azure hybrid       Full Azure
Docker Compose               Docker Compose + Azure DB    Azure Container Apps
PostgreSQL containers        Managed Azure PostgreSQL     Azure PostgreSQL
Valkey containers            Valkey containers or Azure   Azure Cache for Redis
Garage for storage (MinIO replaced Feb 2026)            Azure Blob Storage           Azure Blob Storage
~$200/month                  ~$800-1,200/month            ~$3,500+/month
```

Migration is **additive** — move one layer at a time. No rewrites. No downtime architecture changes.

---

## What Makes This Portable

The application is already structured for this:

1. **All config via environment variables** — no hardcoded hosts, ports, or credentials
2. **Storage abstracted via Laravel filesystem driver** — swap Garage for Azure Blob by changing `FILESYSTEM_DISK` and three env vars
3. **Secrets abstracted via vault driver** — swap local .env secrets for Azure Key Vault by changing the provider class
4. **Stateless app containers** — any container handles any request; nothing stored locally
5. **14 databases as connection strings** — move any database to managed Azure PostgreSQL by updating one env var; the rest of the app is unaware

---

## Stage 1 — On-Prem Production with Docker

### Recommended Hardware

**Single Server (MVP — under $200/month total)**
Hetzner AX102 dedicated:
- 24-core AMD EPYC 9354P
- 128GB ECC RAM
- 2× 1.92TB NVMe SSD (RAID 1 or ZFS mirror)
- 1Gbps uplink unmetered
- €189/month (~$205)

Comfortably runs all 14 PostgreSQL databases, 5 Valkey instances, the app, queue workers, and Garage object storage simultaneously with room to grow to hundreds of active leases.

**Two Servers (Recommended for production)**

| Server | Role | Hetzner | Cost |
|---|---|---|---|
| App server | Laravel, queue workers, Garage | AX52 (12-core, 64GB) | €79/mo |
| DB server | All 14 PostgreSQL + 5 Valkey | AX102 (24-core, 128GB) | €189/mo |
| **Total** | | | **~$290/mo** |

### On the Host — Only Install This

```bash
# Ubuntu 24.04 LTS
apt install docker.io docker-compose-v2 nginx certbot python3-certbot-nginx ufw fail2ban

# That's it. Everything else runs in containers.
```

Nginx on the host handles SSL termination and proxies to the app container. All application services run inside Docker.

---

## Stage 2 — Hybrid (Move Databases First)

When you're ready to start moving toward Azure, databases migrate first — before the app moves. This is the lowest-risk transition because:

- App containers keep running on-prem unchanged
- You point one connection string at Azure PostgreSQL
- If it works, move the next one
- If it doesn't, revert the env var

**Migration order for databases:**

```
1. DB 8  Analytics      — lowest risk, read-only ETL target
2. DB 14 Research       — air-gapped, no app writes
3. DB 12 Platform       — config only, heavily cached
4. DB 7  Communications — high write but non-critical
5. DB 6  Commerce       — marketplace and auctions
6. DB 5  Wildlife       — field data, high write
7. DB 10 Incidents      — safety records
8. DB 11 Documents      — file metadata
9. DB 2  Property       — listings, high read
10. DB 13 Geospatial    — PostGIS (needs Azure Flexible Server + PostGIS extension)
11. DB 3  Lease         — legal records
12. DB 9  Audit         — append-only compliance
13. DB 1  Identity      — auth and PII (highest sensitivity)
14. DB 4  Billing       — PCI (last — most compliance-sensitive)
```

Each migration is:
1. Provision Azure PostgreSQL Flexible Server instance
2. `pg_dump` the database from on-prem
3. `pg_restore` into Azure
4. Update the connection env var in `.env.prod`
5. Restart app containers
6. Monitor for 24–48 hours
7. Decommission the on-prem container

Storage migrates in parallel: Garage → Azure Blob is a one-time `rclone sync` + env var change.

---

## Stage 3 — Full Azure

Once all databases are in Azure, move the app containers last. Options at that point:

| Option | Best for |
|---|---|
| **Azure Container Apps** | Simplest — managed scaling, no Kubernetes knowledge needed |
| **Azure Kubernetes Service** | If you need fine-grained control or have a DevOps team |
| **Azure App Service (containers)** | Simplest ops, least flexible |

Recommended: **Azure Container Apps.** It runs the same Docker images with minimal config change — you provide the image, the env vars, and the scaling rules.

---

## The Docker Image Strategy

One `Dockerfile` produces one image. That image runs as:
- The web server (serves HTTP)
- The queue worker (runs jobs)
- The scheduler (runs cron tasks)

The `CMD` in the compose file determines the role.

```
Same image → different CMD:
  web:     php-fpm + nginx
  worker:  php artisan queue:work
  scheduler: php artisan schedule:run (loop)
```

This means you build once, push to a registry (Azure Container Registry or Docker Hub private), and pull the same image on-prem and in Azure.

---

## Container Registry

Use **Azure Container Registry (ACR)** from day one — even while running on-prem. It's $5/month for the Basic tier and means your images are already in Azure when you migrate. On-prem servers pull images from ACR over the internet on deploy.

```bash
# Build and push
docker build -t ahregistry.azurecr.io/american-headhunter/app:latest .
docker push ahregistry.azurecr.io/american-headhunter/app:latest

# On-prem server pulls the same image
docker pull ahregistry.azurecr.io/american-headhunter/app:latest
```
