<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateAll extends Command
{
    protected $signature = 'migrate:all
                            {--fresh : Drop all tables before migrating}
                            {--seed : Run seeders after migration}
                            {--force : Force the operation in production}';

    protected $description = 'Run migrations across all 14 databases in dependency order';

    // Migration order respects cross-DB UUID references.
    // Databases that are referenced by others must run first.
    private const ORDER = [
        'identity',       // DB 1  — no dependencies
        'platform',       // DB 12 — no dependencies
        'geospatial',     // DB 13 — no dependencies
        'property',       // DB 2  — references identity, geospatial
        'lease',          // DB 3  — references identity, property
        'billing',        // DB 4  — references identity, lease
        'wildlife',       // DB 5  — references identity, lease, geospatial
        'commerce',       // DB 6  — references identity, property, billing
        'communications', // DB 7  — references identity, lease
        'audit',          // DB 9  — references identity
        'incidents',      // DB 10 — references identity, lease
        'documents',      // DB 11 — references identity, lease
        'analytics',      // DB 8  — ETL-populated, migrated last among app DBs
        'research',       // DB 14 — ETL only, always last
    ];

    // DB 8's app-facing connection (`analytics`) is ah_readonly (SELECT only).
    // Migrate it as its owner (`analytics_etl` = ah_etl) so the migrator can
    // create tables and the `migrations` repository table.
    private const MIGRATOR_CONNECTION = [
        'analytics' => 'analytics_etl',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && app()->isProduction()) {
            if (! $this->confirm('You are in production. Continue?')) {
                return self::FAILURE;
            }
        }

        $fresh  = $this->option('fresh');
        $seed   = $this->option('seed');
        $failed = [];

        foreach (self::ORDER as $connection) {
            $this->info("──────────────────────────────────────────");
            $this->info("  Database: {$connection}");
            $this->info("──────────────────────────────────────────");

            $result = $this->runMigration($connection, $fresh);

            if ($result !== self::SUCCESS) {
                $failed[] = $connection;
                $this->error("  FAILED: {$connection}");
            }
        }

        if ($seed && empty($failed)) {
            $this->info("Running seeders...");
            $this->call('db:seed', ['--force' => $this->option('force')]);
        }

        if (! empty($failed)) {
            $this->error("\nFailed databases: " . implode(', ', $failed));
            return self::FAILURE;
        }

        $this->info("\nAll 14 databases migrated successfully.");
        return self::SUCCESS;
    }

    private function runMigration(string $connection, bool $fresh): int
    {
        $migrationPath = database_path("migrations/{$connection}");

        if (! is_dir($migrationPath)) {
            $this->warn("  No migration directory found for '{$connection}' — skipping.");
            return self::SUCCESS;
        }

        $args = [
            '--database' => self::MIGRATOR_CONNECTION[$connection] ?? $connection,
            '--path'     => "database/migrations/{$connection}",
            '--force'    => true,
        ];

        if ($fresh) {
            return $this->call('migrate:fresh', $args);
        }

        return $this->call('migrate', $args);
    }
}
