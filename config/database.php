<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    | The identity database is the default. Every model and migration MUST
    | declare its connection explicitly — never rely on this default.
    */

    'default' => env('DB_CONNECTION', 'identity'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections — 14 purpose-built PostgreSQL databases
    |--------------------------------------------------------------------------
    | Each connection maps to one security/compliance domain.
    | No cross-database SQL joins. Cross-DB references are UUID columns only.
    */

    'connections' => [

        // ── DB 1: Identity & Authentication ──────────────────────────────────
        'identity' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_IDENTITY_HOST', 'postgres'),
            'port'        => env('DB_IDENTITY_PORT', '5432'),
            'database'    => env('DB_IDENTITY_DATABASE', 'ah_identity'),
            'username'    => env('DB_IDENTITY_USERNAME', 'ah_runtime'),
            'password'    => env('DB_IDENTITY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 2: Property & Land ────────────────────────────────────────────
        'property' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_PROPERTY_HOST', 'postgres'),
            'port'        => env('DB_PROPERTY_PORT', '5432'),
            'database'    => env('DB_PROPERTY_DATABASE', 'ah_property'),
            'username'    => env('DB_PROPERTY_USERNAME', 'ah_runtime'),
            'password'    => env('DB_PROPERTY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        'property_read' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_PROPERTY_READ_HOST', env('DB_PROPERTY_HOST', 'postgres')),
            'port'        => env('DB_PROPERTY_READ_PORT', env('DB_PROPERTY_PORT', '5432')),
            'database'    => env('DB_PROPERTY_DATABASE', 'ah_property'),
            'username'    => env('DB_READONLY_USERNAME', 'ah_readonly'),
            'password'    => env('DB_READONLY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 3: Lease & Contract ───────────────────────────────────────────
        'lease' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_LEASE_HOST', 'postgres'),
            'port'        => env('DB_LEASE_PORT', '5432'),
            'database'    => env('DB_LEASE_DATABASE', 'ah_lease'),
            'username'    => env('DB_LEASE_USERNAME', 'ah_runtime'),
            'password'    => env('DB_LEASE_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 4: Billing & Payments ─────────────────────────────────────────
        'billing' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_BILLING_HOST', 'postgres'),
            'port'        => env('DB_BILLING_PORT', '5432'),
            'database'    => env('DB_BILLING_DATABASE', 'ah_billing'),
            'username'    => env('DB_BILLING_USERNAME', 'ah_runtime'),
            'password'    => env('DB_BILLING_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 5: Wildlife & Field Operations ────────────────────────────────
        'wildlife' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_WILDLIFE_HOST', 'postgres'),
            'port'        => env('DB_WILDLIFE_PORT', '5432'),
            'database'    => env('DB_WILDLIFE_DATABASE', 'ah_wildlife'),
            'username'    => env('DB_WILDLIFE_USERNAME', 'ah_runtime'),
            'password'    => env('DB_WILDLIFE_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        'wildlife_read' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_WILDLIFE_READ_HOST', env('DB_WILDLIFE_HOST', 'postgres')),
            'port'        => env('DB_WILDLIFE_READ_PORT', env('DB_WILDLIFE_PORT', '5432')),
            'database'    => env('DB_WILDLIFE_DATABASE', 'ah_wildlife'),
            'username'    => env('DB_READONLY_USERNAME', 'ah_readonly'),
            'password'    => env('DB_READONLY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 6: Commerce & Marketplace ─────────────────────────────────────
        'commerce' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_COMMERCE_HOST', 'postgres'),
            'port'        => env('DB_COMMERCE_PORT', '5432'),
            'database'    => env('DB_COMMERCE_DATABASE', 'ah_commerce'),
            'username'    => env('DB_COMMERCE_USERNAME', 'ah_runtime'),
            'password'    => env('DB_COMMERCE_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 7: Communications ──────────────────────────────────────────────
        'communications' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_COMMUNICATIONS_HOST', 'postgres'),
            'port'        => env('DB_COMMUNICATIONS_PORT', '5432'),
            'database'    => env('DB_COMMUNICATIONS_DATABASE', 'ah_communications'),
            'username'    => env('DB_COMMUNICATIONS_USERNAME', 'ah_runtime'),
            'password'    => env('DB_COMMUNICATIONS_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 8: Analytics (read-only for app, ETL-populated) ───────────────
        'analytics' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_ANALYTICS_HOST', 'postgres'),
            'port'        => env('DB_ANALYTICS_PORT', '5432'),
            'database'    => env('DB_ANALYTICS_DATABASE', 'ah_analytics'),
            'username'    => env('DB_READONLY_USERNAME', 'ah_readonly'),
            'password'    => env('DB_READONLY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        'analytics_etl' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_ANALYTICS_HOST', 'postgres'),
            'port'        => env('DB_ANALYTICS_PORT', '5432'),
            'database'    => env('DB_ANALYTICS_DATABASE', 'ah_analytics'),
            'username'    => env('DB_ETL_USERNAME', 'ah_etl'),
            'password'    => env('DB_ETL_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // Restricted read path for the sensitive revenue_snapshots table. Uses
        // ah_system (the trusted admin/queue role) because ah_readonly has no
        // SELECT grant on revenue_snapshots. Only the admin dashboard Revenue tab
        // reads through this connection.
        'analytics_admin' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_ANALYTICS_HOST', 'postgres'),
            'port'        => env('DB_ANALYTICS_PORT', '5432'),
            'database'    => env('DB_ANALYTICS_DATABASE', 'ah_analytics'),
            'username'    => env('DB_SYSTEM_USERNAME', 'ah_system'),
            'password'    => env('DB_SYSTEM_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 9: Audit & Compliance (append-only) ───────────────────────────
        'audit' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_AUDIT_HOST', 'postgres'),
            'port'        => env('DB_AUDIT_PORT', '5432'),
            'database'    => env('DB_AUDIT_DATABASE', 'ah_audit'),
            'username'    => env('DB_AUDIT_USERNAME', 'ah_runtime'),
            'password'    => env('DB_AUDIT_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 10: Incidents & Safety ─────────────────────────────────────────
        'incidents' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_INCIDENTS_HOST', 'postgres'),
            'port'        => env('DB_INCIDENTS_PORT', '5432'),
            'database'    => env('DB_INCIDENTS_DATABASE', 'ah_incidents'),
            'username'    => env('DB_INCIDENTS_USERNAME', 'ah_runtime'),
            'password'    => env('DB_INCIDENTS_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 11: Documents & Media ──────────────────────────────────────────
        'documents' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_DOCUMENTS_HOST', 'postgres'),
            'port'        => env('DB_DOCUMENTS_PORT', '5432'),
            'database'    => env('DB_DOCUMENTS_DATABASE', 'ah_documents'),
            'username'    => env('DB_DOCUMENTS_USERNAME', 'ah_runtime'),
            'password'    => env('DB_DOCUMENTS_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 12: Platform Configuration ────────────────────────────────────
        'platform' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_PLATFORM_HOST', 'postgres'),
            'port'        => env('DB_PLATFORM_PORT', '5432'),
            'database'    => env('DB_PLATFORM_DATABASE', 'ah_platform'),
            'username'    => env('DB_PLATFORM_USERNAME', 'ah_runtime'),
            'password'    => env('DB_PLATFORM_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 13: Geospatial (PostGIS) ───────────────────────────────────────
        'geospatial' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_GEOSPATIAL_HOST', 'postgres'),
            'port'        => env('DB_GEOSPATIAL_PORT', '5432'),
            'database'    => env('DB_GEOSPATIAL_DATABASE', 'ah_geospatial'),
            'username'    => env('DB_GEOSPATIAL_USERNAME', 'ah_runtime'),
            'password'    => env('DB_GEOSPATIAL_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        'geospatial_read' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_GEOSPATIAL_READ_HOST', env('DB_GEOSPATIAL_HOST', 'postgres')),
            'port'        => env('DB_GEOSPATIAL_READ_PORT', env('DB_GEOSPATIAL_PORT', '5432')),
            'database'    => env('DB_GEOSPATIAL_DATABASE', 'ah_geospatial'),
            'username'    => env('DB_READONLY_USERNAME', 'ah_readonly'),
            'password'    => env('DB_READONLY_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        // ── DB 14: Research Dataset (ETL only — no application access) ────────
        'research' => [
            'driver'      => 'pgsql',
            'host'        => env('DB_RESEARCH_HOST', 'postgres'),
            'port'        => env('DB_RESEARCH_PORT', '5432'),
            'database'    => env('DB_RESEARCH_DATABASE', 'ah_research'),
            'username'    => env('DB_ETL_USERNAME', 'ah_etl'),
            'password'    => env('DB_ETL_PASSWORD', ''),
            'charset'     => 'utf8',
            'prefix'      => '',
            'search_path' => 'public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Valkey / Redis Connections — 5 isolated clusters
    |--------------------------------------------------------------------------
    | Cluster 1 — sessions    (6379)
    | Cluster 2 — default     (6380)  app cache, listings, config
    | Cluster 3 — queue       (6381)  job queue
    | Cluster 4 — auction     (6382)  live bid state, countdowns
    | Cluster 5 — ratelimit   (6383)  per-user API throttle counters
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster'    => env('REDIS_CLUSTER', 'redis'),
            'prefix'     => '',
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        // Cluster 1 — sessions
        'sessions' => [
            'host'     => env('VALKEY_SESSIONS_HOST', 'valkey_sessions'),
            'port'     => env('VALKEY_SESSIONS_PORT', '6379'),
            'password' => env('VALKEY_SESSIONS_PASSWORD', null),
            'database' => 0,
        ],

        // Cluster 2 — app cache (also aliased as 'default' for Cache facade)
        'default' => [
            'host'     => env('VALKEY_CACHE_HOST', 'valkey_cache'),
            'port'     => env('VALKEY_CACHE_PORT', '6380'),
            'password' => env('VALKEY_CACHE_PASSWORD', null),
            'database' => 0,
        ],

        'cache' => [
            'host'     => env('VALKEY_CACHE_HOST', 'valkey_cache'),
            'port'     => env('VALKEY_CACHE_PORT', '6380'),
            'password' => env('VALKEY_CACHE_PASSWORD', null),
            'database' => 0,
        ],

        // Cluster 3 — job queue
        'queue' => [
            'host'     => env('VALKEY_QUEUE_HOST', 'valkey_queue'),
            'port'     => env('VALKEY_QUEUE_PORT', '6381'),
            'password' => env('VALKEY_QUEUE_PASSWORD', null),
            'database' => 0,
        ],

        // Cluster 4 — auction live state
        'auction' => [
            'host'     => env('VALKEY_AUCTION_HOST', 'valkey_auction'),
            'port'     => env('VALKEY_AUCTION_PORT', '6382'),
            'password' => env('VALKEY_AUCTION_PASSWORD', null),
            'database' => 0,
        ],

        // Cluster 5 — rate limiting
        'ratelimit' => [
            'host'     => env('VALKEY_RATELIMIT_HOST', 'valkey_ratelimit'),
            'port'     => env('VALKEY_RATELIMIT_PORT', '6383'),
            'password' => env('VALKEY_RATELIMIT_PASSWORD', null),
            'database' => 0,
        ],

    ],

];
