-- American Headhunter — PostgreSQL initialization
-- Creates all 14 application databases and their users.
-- Runs once when the container is first started.

-- ─── Users ───────────────────────────────────────────────────────────────────

-- ah_app     — schema OWNER. Runs migrations, seeders, and ETL. As the table
--              owner it bypasses RLS (this is intentional — migrations/seeders
--              must see and write every row). NEVER used as the application's
--              runtime connection.
-- ah_runtime — application RUNTIME role (HTTP + queue). A non-owner, so RLS
--              policies actually apply to it (SEC-043). Granted DML only.
CREATE USER ah_app      WITH PASSWORD 'secret';
CREATE USER ah_runtime  WITH PASSWORD 'secret';
CREATE USER ah_readonly WITH PASSWORD 'secret';
CREATE USER ah_etl      WITH PASSWORD 'secret';

-- ─── DB 1 — Identity & Authentication ───────────────────────────────────────

CREATE DATABASE ah_identity OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_identity TO ah_app;
GRANT CONNECT ON DATABASE ah_identity TO ah_runtime;

-- ─── DB 2 — Property & Land ─────────────────────────────────────────────────

CREATE DATABASE ah_property OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_property TO ah_app;
GRANT CONNECT ON DATABASE ah_property TO ah_runtime;
GRANT CONNECT ON DATABASE ah_property TO ah_readonly;

-- ─── DB 3 — Lease & Contract ────────────────────────────────────────────────

CREATE DATABASE ah_lease OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_lease TO ah_app;
GRANT CONNECT ON DATABASE ah_lease TO ah_runtime;

-- ─── DB 4 — Billing & Payments ──────────────────────────────────────────────

CREATE DATABASE ah_billing OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_billing TO ah_app;
GRANT CONNECT ON DATABASE ah_billing TO ah_runtime;

-- ─── DB 5 — Wildlife & Field Operations ─────────────────────────────────────

CREATE DATABASE ah_wildlife OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_wildlife TO ah_app;
GRANT CONNECT ON DATABASE ah_wildlife TO ah_runtime;
GRANT CONNECT ON DATABASE ah_wildlife TO ah_readonly;

-- ─── DB 6 — Commerce & Marketplace ─────────────────────────────────────────

CREATE DATABASE ah_commerce OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_commerce TO ah_app;
GRANT CONNECT ON DATABASE ah_commerce TO ah_runtime;

-- ─── DB 7 — Communications ──────────────────────────────────────────────────

CREATE DATABASE ah_communications OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_communications TO ah_app;
GRANT CONNECT ON DATABASE ah_communications TO ah_runtime;

-- ─── DB 8 — Analytics (ETL-populated, read-only for app) ────────────────────

CREATE DATABASE ah_analytics OWNER ah_etl;
GRANT CONNECT ON DATABASE ah_analytics TO ah_readonly;
GRANT CONNECT ON DATABASE ah_analytics TO ah_etl;

-- ─── DB 9 — Audit & Compliance (append-only) ────────────────────────────────

CREATE DATABASE ah_audit OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_audit TO ah_app;
GRANT CONNECT ON DATABASE ah_audit TO ah_runtime;

-- ─── DB 10 — Incidents & Safety ─────────────────────────────────────────────

CREATE DATABASE ah_incidents OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_incidents TO ah_app;
GRANT CONNECT ON DATABASE ah_incidents TO ah_runtime;

-- ─── DB 11 — Documents & Media ──────────────────────────────────────────────

CREATE DATABASE ah_documents OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_documents TO ah_app;
GRANT CONNECT ON DATABASE ah_documents TO ah_runtime;

-- ─── DB 12 — Platform Configuration ─────────────────────────────────────────

CREATE DATABASE ah_platform OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_platform TO ah_app;
GRANT CONNECT ON DATABASE ah_platform TO ah_runtime;

-- ─── DB 13 — Geospatial (PostGIS) ───────────────────────────────────────────

CREATE DATABASE ah_geospatial OWNER ah_app;
GRANT ALL PRIVILEGES ON DATABASE ah_geospatial TO ah_app;
GRANT CONNECT ON DATABASE ah_geospatial TO ah_runtime;
GRANT CONNECT ON DATABASE ah_geospatial TO ah_readonly;

-- ─── DB 14 — Research Dataset (ETL only, no app access) ─────────────────────

CREATE DATABASE ah_research OWNER ah_etl;
GRANT ALL PRIVILEGES ON DATABASE ah_research TO ah_etl;

-- ─── Enable PostGIS on geospatial DB ─────────────────────────────────────────
-- (must connect to the database first — handled by the shell script below)
