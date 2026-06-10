SHELL := /bin/bash
.DEFAULT_GOAL := help
COMPOSE := docker compose
APP := $(COMPOSE) exec app

##@ Local dev stack

.PHONY: up
up: ## Start all services
	$(COMPOSE) up -d
	@echo "App: http://localhost | Mailpit: http://localhost:8025"

.PHONY: down
down: ## Stop all services
	$(COMPOSE) down

.PHONY: restart
restart: down up ## Restart all services

.PHONY: build
build: ## Rebuild the app image
	$(COMPOSE) build app

.PHONY: fresh
fresh: ## Fresh install — destroy volumes, rebuild, boot, migrate, seed
	$(COMPOSE) down -v
	$(COMPOSE) build app
	$(COMPOSE) up -d
	@echo "Waiting for Postgres to be ready..."
	@sleep 5
	$(APP) php artisan migrate:all --fresh --seed
	@echo "Fresh install complete."

.PHONY: logs
logs: ## Tail all service logs
	$(COMPOSE) logs -f

.PHONY: logs-app
logs-app: ## Tail app logs only
	$(COMPOSE) logs -f app

##@ Application

.PHONY: shell
shell: ## Open a shell in the app container
	$(APP) bash

.PHONY: artisan
artisan: ## Run an artisan command — usage: make artisan CMD="migrate:all"
	$(APP) php artisan $(CMD)

.PHONY: composer
composer: ## Run composer — usage: make composer CMD="require vendor/pkg"
	$(APP) composer $(CMD)

.PHONY: tinker
tinker: ## Launch Tinker REPL
	$(APP) php artisan tinker

##@ Database migrations

.PHONY: migrate
migrate: ## Run all 14 database migrations in dependency order
	$(APP) php artisan migrate:all

.PHONY: migrate-fresh
migrate-fresh: ## Fresh migrate all databases (drops all tables)
	$(APP) php artisan migrate:all --fresh

.PHONY: migrate-seed
migrate-seed: ## Fresh migrate + seed all databases
	$(APP) php artisan migrate:all --fresh --seed

.PHONY: migrate-single
migrate-single: ## Migrate one database — usage: make migrate-single DB=identity
	$(APP) php artisan migrate:single $(DB)

.PHONY: flush-cache
flush-cache: ## Flush app cache (Valkey cluster 2 — does not touch sessions or queue)
	$(APP) php artisan cache:clear
	@echo "App cache flushed."

##@ psql shortcuts — direct access to each database

.PHONY: psql-identity
psql-identity:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_identity

.PHONY: psql-property
psql-property:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_property

.PHONY: psql-lease
psql-lease:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_lease

.PHONY: psql-billing
psql-billing:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_billing

.PHONY: psql-wildlife
psql-wildlife:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_wildlife

.PHONY: psql-commerce
psql-commerce:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_commerce

.PHONY: psql-communications
psql-communications:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_communications

.PHONY: psql-analytics
psql-analytics:
	$(COMPOSE) exec postgres psql -U ah_etl -d ah_analytics

.PHONY: psql-audit
psql-audit:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_audit

.PHONY: psql-incidents
psql-incidents:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_incidents

.PHONY: psql-documents
psql-documents:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_documents

.PHONY: psql-platform
psql-platform:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_platform

.PHONY: psql-geospatial
psql-geospatial:
	$(COMPOSE) exec postgres psql -U ah_app -d ah_geospatial

.PHONY: psql-research
psql-research:
	$(COMPOSE) exec postgres psql -U ah_etl -d ah_research

##@ Valkey CLI shortcuts

.PHONY: valkey-sessions
valkey-sessions:
	$(COMPOSE) exec valkey_sessions valkey-cli

.PHONY: valkey-cache
valkey-cache:
	$(COMPOSE) exec valkey_cache valkey-cli

.PHONY: valkey-queue
valkey-queue:
	$(COMPOSE) exec valkey_queue valkey-cli

.PHONY: valkey-auction
valkey-auction:
	$(COMPOSE) exec valkey_auction valkey-cli

.PHONY: valkey-ratelimit
valkey-ratelimit:
	$(COMPOSE) exec valkey_ratelimit valkey-cli

##@ Tests

.PHONY: test
test: ## Run the test suite
	$(APP) php artisan test

.PHONY: test-coverage
test-coverage: ## Run tests with coverage report
	$(APP) php artisan test --coverage

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
