#!/bin/bash
# Enables PostGIS on the geospatial database.
# Runs after init.sql because it must connect to ah_geospatial directly.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_geospatial" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS postgis;
    CREATE EXTENSION IF NOT EXISTS postgis_topology;
    CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
    CREATE EXTENSION IF NOT EXISTS postgis_tiger_geocoder;
    GRANT ALL ON SCHEMA public TO ah_app;
EOSQL

# Enable pgcrypto on identity and billing databases
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_identity" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    GRANT ALL ON SCHEMA public TO ah_app;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_billing" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    GRANT ALL ON SCHEMA public TO ah_app;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_property" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    GRANT ALL ON SCHEMA public TO ah_app;
    GRANT USAGE ON SCHEMA public TO ah_readonly;
EOSQL

# Grant schema-level access to ah_readonly on analytics
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_analytics" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    GRANT ALL ON SCHEMA public TO ah_etl;
    GRANT USAGE ON SCHEMA public TO ah_readonly;
EOSQL

# Enable uuid-ossp and pgcrypto on remaining databases
for db in ah_lease ah_wildlife ah_commerce ah_communications ah_audit ah_incidents ah_documents ah_platform; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-EOSQL
        CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
        CREATE EXTENSION IF NOT EXISTS pgcrypto;
        GRANT ALL ON SCHEMA public TO ah_app;
EOSQL
done

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "ah_research" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    GRANT ALL ON SCHEMA public TO ah_etl;
EOSQL

echo "PostGIS and extensions initialized successfully."
