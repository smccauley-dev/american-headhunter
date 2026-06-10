# CI/CD Pipeline — GitHub Actions

## Overview

Same pipeline works for both on-prem and Azure deployments.
The only difference is the deploy step at the end.

---

## .github/workflows/deploy.yml

```yaml
name: Build and Deploy

on:
  push:
    branches: [main, staging]
  pull_request:
    branches: [main]

env:
  IMAGE_NAME: american-headhunter-app

jobs:

  # ──────────────────────────────────────────────────────────
  # Test
  # ──────────────────────────────────────────────────────────
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgis/postgis:16-3.4-alpine
        env:
          POSTGRES_PASSWORD: secret
          POSTGRES_DB: platform_test
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
        ports:
          - 5432:5432

      valkey:
        image: valkey/valkey:8-alpine
        ports:
          - 6379:6379

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_pgsql, redis, gd, zip, bcmath, intl
          coverage: pcov

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install Node dependencies
        run: npm ci

      - name: Build assets
        run: npm run build

      - name: Copy .env
        run: cp .env.testing .env

      - name: Generate key
        run: php artisan key:generate

      - name: Run migrations (test DB)
        run: php artisan migrate:all --force
        env:
          DB_IDENTITY_HOST: 127.0.0.1
          DB_IDENTITY_PORT: 5432
          # All 14 DBs point to same test instance with different DB names

      - name: Run tests
        run: php artisan test --parallel --coverage --min=80
        env:
          DB_IDENTITY_HOST: 127.0.0.1

  # ──────────────────────────────────────────────────────────
  # Build Docker Image
  # ──────────────────────────────────────────────────────────
  build:
    needs: test
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' || github.ref == 'refs/heads/staging'

    outputs:
      image_tag: ${{ steps.meta.outputs.version }}

    steps:
      - uses: actions/checkout@v4

      - name: Docker meta
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ secrets.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}
          tags: |
            type=sha,prefix=,format=short
            type=ref,event=branch
            type=raw,value=latest,enable=${{ github.ref == 'refs/heads/main' }}

      - name: Log in to registry
        uses: docker/login-action@v3
        with:
          registry: ${{ secrets.REGISTRY_HOST }}
          username: ${{ secrets.REGISTRY_USERNAME }}
          password: ${{ secrets.REGISTRY_PASSWORD }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          cache-from: type=registry,ref=${{ secrets.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:buildcache
          cache-to: type=registry,ref=${{ secrets.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:buildcache,mode=max

  # ──────────────────────────────────────────────────────────
  # Deploy — On-Prem (SSH)
  # Used when DEPLOY_TARGET secret = 'onprem'
  # ──────────────────────────────────────────────────────────
  deploy-onprem:
    needs: build
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' && vars.DEPLOY_TARGET == 'onprem'
    environment: production

    steps:
      - name: Deploy to on-prem server
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: ${{ secrets.DEPLOY_USER }}
          key: ${{ secrets.DEPLOY_SSH_KEY }}
          script: |
            cd /opt/american-headhunter

            # Pull new image
            echo "${{ secrets.REGISTRY_PASSWORD }}" | \
              docker login ${{ secrets.REGISTRY_HOST }} \
              -u ${{ secrets.REGISTRY_USERNAME }} --password-stdin

            export APP_IMAGE=${{ secrets.REGISTRY_HOST }}/american-headhunter-app:${{ needs.build.outputs.image_tag }}
            docker compose -f docker-compose.prod.yml pull app

            # Run migrations before swapping container
            docker compose -f docker-compose.prod.yml run --rm \
              -e RUN_MIGRATIONS=true app \
              php artisan migrate:all --force

            # Zero-downtime swap
            docker compose -f docker-compose.prod.yml up -d --no-deps app

            # Health check
            sleep 10
            curl -f http://localhost/healthz || \
              (docker compose -f docker-compose.prod.yml logs app && exit 1)

            echo "Deploy complete: $APP_IMAGE"

  # ──────────────────────────────────────────────────────────
  # Deploy — Azure Container Apps
  # Used when DEPLOY_TARGET secret = 'azure'
  # ──────────────────────────────────────────────────────────
  deploy-azure:
    needs: build
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main' && vars.DEPLOY_TARGET == 'azure'
    environment: production

    steps:
      - name: Azure login
        uses: azure/login@v2
        with:
          creds: ${{ secrets.AZURE_CREDENTIALS }}

      - name: Deploy to Azure Container Apps
        uses: azure/container-apps-deploy-action@v1
        with:
          resourceGroup: american-headhunter-prod
          containerAppName: american-headhunter-app
          imageToDeploy: ${{ secrets.REGISTRY_HOST }}/american-headhunter-app:${{ needs.build.outputs.image_tag }}

      - name: Run migrations (one-off job)
        run: |
          az containerapp job create \
            --name migrate-job \
            --resource-group american-headhunter-prod \
            --environment american-headhunter-env \
            --image ${{ secrets.REGISTRY_HOST }}/american-headhunter-app:${{ needs.build.outputs.image_tag }} \
            --replica-timeout 600 \
            --replica-retry-limit 1 \
            --trigger-type Manual \
            --parallelism 1 \
            --replica-completion-count 1 \
            --env-vars RUN_MIGRATIONS=true

          az containerapp job start \
            --name migrate-job \
            --resource-group american-headhunter-prod
```

---

## Switching Between On-Prem and Azure Deploys

Only one GitHub repo variable needs to change:

```bash
# Deploy to on-prem
gh variable set DEPLOY_TARGET --body "onprem"

# Deploy to Azure
gh variable set DEPLOY_TARGET --body "azure"
```

The `build` job is identical in both cases — the same image is built and
pushed to the registry. Only the final deploy step differs.

---

## Required GitHub Secrets

```bash
# Registry (works for both local Docker registry and Azure Container Registry)
REGISTRY_HOST          # e.g. your-server:5000 or ahregistry.azurecr.io
REGISTRY_USERNAME
REGISTRY_PASSWORD

# On-prem deployment
DEPLOY_HOST            # server IP or hostname
DEPLOY_USER            # SSH user
DEPLOY_SSH_KEY         # private SSH key

# Azure deployment
AZURE_CREDENTIALS      # az ad sp create-for-rbac output (JSON)
```

## Setting Up a Local Docker Registry (On-Prem)

If you don't want to use Docker Hub or Azure Container Registry while on-prem:

```bash
# Run a local registry on your server
docker run -d \
  --restart unless-stopped \
  --name registry \
  -v registry_data:/var/lib/registry \
  -p 5000:5000 \
  registry:2

# Allow insecure registry on CI runner (or configure TLS)
# /etc/docker/daemon.json on the runner:
{
  "insecure-registries": ["your-server:5000"]
}

# REGISTRY_HOST secret = your-server:5000
```
