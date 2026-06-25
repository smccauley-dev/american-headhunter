<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateSingle extends Command
{
    protected $signature = 'migrate:single
                            {database : The database connection key (e.g. identity, lease, geospatial)}
                            {--fresh : Drop all tables before migrating}
                            {--seed : Run the database seeder after migration}
                            {--force : Force the operation in production}';

    protected $description = 'Run migrations for a single database connection';

    private const VALID = [
        'identity', 'property', 'lease', 'billing', 'wildlife',
        'commerce', 'communications', 'analytics', 'audit', 'incidents',
        'documents', 'platform', 'geospatial', 'research',
    ];

    // Some DBs read through a non-owner connection (ah_readonly) but must be
    // MIGRATED as their owner. DB 8's app-facing key is `analytics` (ah_readonly,
    // SELECT only); its owner is `analytics_etl` (ah_etl), so the migrator — which
    // also writes the `migrations` repository table — has to run there.
    private const MIGRATOR_CONNECTION = [
        'analytics' => 'analytics_etl',
    ];

    public function handle(): int
    {
        $connection = $this->argument('database');

        if (! in_array($connection, self::VALID, true)) {
            $this->error("Unknown connection '{$connection}'. Valid options: " . implode(', ', self::VALID));
            return self::FAILURE;
        }

        if (! $this->option('force') && app()->isProduction()) {
            if (! $this->confirm("You are in production. Migrate '{$connection}'?")) {
                return self::FAILURE;
            }
        }

        $migrationPath = database_path("migrations/{$connection}");

        if (! is_dir($migrationPath)) {
            $this->warn("No migration directory found at database/migrations/{$connection}");
            return self::FAILURE;
        }

        $args = [
            '--database' => self::MIGRATOR_CONNECTION[$connection] ?? $connection,
            '--path'     => "database/migrations/{$connection}",
            '--force'    => true,
        ];

        if ($this->option('fresh')) {
            $result = $this->call('migrate:fresh', $args);
        } else {
            $result = $this->call('migrate', $args);
        }

        if ($result === self::SUCCESS && $this->option('seed')) {
            $seederClass = 'Database\\Seeders\\' . ucfirst($connection) . 'Seeder';
            if (class_exists($seederClass)) {
                $this->call('db:seed', ['--class' => $seederClass, '--force' => true]);
            } else {
                $this->warn("No seeder found for '{$connection}' (looked for {$seederClass})");
            }
        }

        return $result;
    }
}
