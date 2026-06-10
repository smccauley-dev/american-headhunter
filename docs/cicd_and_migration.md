# CI/CD & Azure Migration Guide

## CI/CD Pipeline — GitHub Actions

The same pipeline builds and deploys to both on-prem and Azure. The deployment target is determined by which branch triggers the workflow.

---

## .github/workflows/deploy.yml

```yaml
name: Build & Deploy

on:
  push:
    branches:
      - main        # → Production (on-prem or Azure depending on stage)
      - staging     # → Staging server

env:
  REGISTRY: ahregistry.azurecr.io
  IMAGE_NAME: american-headhunter/app

jobs:

  # ────────────────────────────────────────────────────────
  # 1. Test
  # ────────────────────────────────────────────────────────
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_PASSWORD: testing
          POSTGRES_DB: platform_testing
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
      valkey:
        image: valkey/valkey:8-alpine
        options: --health-cmd "valkey-cli ping" --health-interval 10s

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_pgsql, redis, gd, zip, intl, bcmath

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Copy .env
        run: cp .env.testing .env

      - name: Generate key
        run: php artisan key:generate

      - name: Run tests
        run: php artisan test --parallel

  # ────────────────────────────────────────────────────────
  # 2. Build & Push image to ACR
  # ────────────────────────────────────────────────────────
  build:
    needs: test
    runs-on: ubuntu-latest
    outputs:
      image_tag: ${{ steps.meta.outputs.tags }}
      version: ${{ steps.meta.outputs.version }}

    steps:
      - uses: actions/checkout@v4

      - name: Login to Azure Container Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ secrets.ACR_USERNAME }}
          password: ${{ secrets.ACR_PASSWORD }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=sha,prefix=,suffix=,format=short
            type=raw,value=latest,enable=${{ github.ref == 'refs/heads/main' }}
            type=raw,value=staging,enable=${{ github.ref == 'refs/heads/staging' }}

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
          build-args: |
            APP_VERSION=${{ steps.meta.outputs.version }}

  # ────────────────────────────────────────────────────────
  # 3a. Deploy to on-prem server (Stage 1)
  # ────────────────────────────────────────────────────────
  deploy-onprem:
    needs: build
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' && vars.DEPLOY_TARGET == 'onprem'

    steps:
      - name: Deploy to on-prem via SSH
        uses: appleboy/ssh-action@v1
        with:
          host:     ${{ secrets.SERVER_HOST }}
          username: ${{ secrets.SERVER_USER }}
          key:      ${{ secrets.SERVER_SSH_KEY }}
          script: |
            cd /opt/american-headhunter

            # Pull latest image
            echo "${{ secrets.ACR_PASSWORD }}" | \
              docker login ahregistry.azurecr.io -u ${{ secrets.ACR_USERNAME }} --password-stdin

            APP_VERSION=${{ needs.build.outputs.version }} \
              docker compose -f docker-compose.prod.yml pull app worker worker-priority scheduler

            # Run migrations
            APP_VERSION=${{ needs.build.outputs.version }} \
              docker compose -f docker-compose.prod.yml run --rm app migrate

            # Rolling restart — zero downtime
            APP_VERSION=${{ needs.build.outputs.version }} \
              docker compose -f docker-compose.prod.yml up -d --no-deps app
            sleep 10
            APP_VERSION=${{ needs.build.outputs.version }} \
              docker compose -f docker-compose.prod.yml up -d --no-deps \
                worker worker-priority scheduler

            # Cleanup old images
            docker image prune -f

  # ────────────────────────────────────────────────────────
  # 3b. Deploy to Azure Container Apps (Stage 3)
  # ────────────────────────────────────────────────────────
  deploy-azure:
    needs: build
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' && vars.DEPLOY_TARGET == 'azure'

    steps:
      - name: Azure login
        uses: azure/login@v1
        with:
          creds: ${{ secrets.AZURE_CREDENTIALS }}

      - name: Deploy to Azure Container Apps
        uses: azure/container-apps-deploy-action@v1
        with:
          resourceGroup:      american-headhunter-rg
          containerAppName:   ah-app
          imageToDeploy:      ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:${{ needs.build.outputs.version }}

      - name: Run migrations on Azure
        uses: azure/cli@v1
        with:
          azcliversion: latest
          inlineScript: |
            az containerapp job start \
              --name ah-migrate \
              --resource-group american-headhunter-rg
```

---

## Switching Deployment Target

To switch from on-prem to Azure, change one GitHub Actions variable — no code changes:

```bash
# In GitHub → Settings → Variables and secrets → Actions variables
DEPLOY_TARGET = onprem   # Stage 1 and 2
DEPLOY_TARGET = azure    # Stage 3
```

---

## Azure Migration — Step by Step

When you're ready to move from on-prem to Azure, follow this sequence. Each step is independently reversible.

### Step 0 — Prerequisites (do these on day one, not at migration time)

```bash
# 1. Azure Container Registry — already used for image storage
az acr create --name youracr --resource-group american-headhunter-rg --sku Basic

# 2. Set DEPLOY_TARGET=onprem in GitHub Actions — already deploying images to ACR
# This means on migration day, the images are already in Azure
```

### Step 1 — Move Storage (Garage → Azure Blob)

```bash
# Install rclone
curl https://rclone.org/install.sh | sudo bash

# Configure rclone for both Garage (source) and Azure Blob (destination)
rclone config  # create 'storage' and 'azure' remotes

# Sync all buckets
rclone sync storage:platform-properties azure:platform-properties
rclone sync storage:platform-documents   azure:platform-documents
rclone sync storage:platform-harvests    azure:platform-harvests

# Update .env on server — app keeps running
FILESYSTEM_DISK=azure
AZURE_STORAGE_ACCOUNT=youraccount
AZURE_STORAGE_KEY=yourkey

# Restart app containers
docker compose -f docker-compose.prod.yml up -d --no-deps app
```

### Step 2 — Migrate Databases One at a Time

Start with the lowest-risk databases. For each:

```bash
# 1. Provision Azure PostgreSQL Flexible Server
az postgres flexible-server create \
  --name ah-db-analytics \
  --resource-group american-headhunter-rg \
  --location eastus \
  --tier Burstable \
  --sku-name Standard_B2ms \
  --storage-size 32

# 2. Dump from container
docker exec ah_db_analytics \
  pg_dump -U analytics_user platform_analytics \
  > /tmp/analytics_dump.sql

# 3. Restore to Azure
psql "host=ah-db-analytics.postgres.database.azure.com \
      dbname=platform_analytics \
      user=analytics_admin \
      sslmode=require" \
  < /tmp/analytics_dump.sql

# 4. Update .env on server
DB_ANALYTICS_HOST=ah-db-analytics.postgres.database.azure.com
DB_ANALYTICS_USERNAME=analytics_admin
DB_ANALYTICS_PASSWORD=new_password
DB_ANALYTICS_SSLMODE=require

# 5. Restart app containers
docker compose -f docker-compose.prod.yml up -d --no-deps app worker

# 6. Monitor for 48 hours. If good, stop the container:
docker compose -f docker-compose.prod.yml stop db-analytics
```

Repeat for each database in the migration order from `deployment_strategy.md`.

### Step 3 — Move App to Azure Container Apps

Once all databases and storage are in Azure:

```bash
# Create Container Apps environment
az containerapp env create \
  --name ah-env \
  --resource-group american-headhunter-rg \
  --location eastus

# Create app container
az containerapp create \
  --name ah-app \
  --resource-group american-headhunter-rg \
  --environment ah-env \
  --image ahregistry.azurecr.io/american-headhunter/app:latest \
  --command web \
  --min-replicas 1 \
  --max-replicas 5 \
  --cpu 1.0 \
  --memory 2.0Gi \
  --ingress external \
  --target-port 80 \
  --registry-server ahregistry.azurecr.io \
  --secrets-file azure-secrets.yaml

# Create worker container
az containerapp create \
  --name ah-worker \
  --resource-group american-headhunter-rg \
  --environment ah-env \
  --image ahregistry.azurecr.io/american-headhunter/app:latest \
  --command worker \
  --min-replicas 1 \
  --max-replicas 4 \
  --cpu 0.5 \
  --memory 1.0Gi \
  --ingress disabled

# Create migration job (runs on deploy)
az containerapp job create \
  --name ah-migrate \
  --resource-group american-headhunter-rg \
  --environment ah-env \
  --image ahregistry.azurecr.io/american-headhunter/app:latest \
  --command migrate \
  --trigger-type Manual \
  --replica-timeout 300

# Point GitHub Actions to Azure
# DEPLOY_TARGET = azure
```

### Step 4 — DNS Cutover

```bash
# Get Azure Container Apps ingress FQDN
az containerapp show \
  --name ah-app \
  --resource-group american-headhunter-rg \
  --query "properties.configuration.ingress.fqdn"

# Update Cloudflare DNS
# CNAME americanheadhunter.com → ah-app.azurecontainerapps.io
# Set proxied = true (Cloudflare handles SSL)

# Decommission on-prem app containers
docker compose -f docker-compose.prod.yml stop app worker worker-priority scheduler
```

---

## What Never Changes During Migration

- Zero application code
- Zero database schemas
- Zero migration files
- Zero model or service files
- Zero Filament resources
- Zero API contracts

The entire migration is environment variable changes, DNS updates, and data movement. The application is infrastructure-agnostic by design.
