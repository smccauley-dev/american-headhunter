<?php

namespace App\Providers;

use App\Database\ConnectionRole;
use Illuminate\Support\ServiceProvider;

/**
 * SEC-043 — selects the database role for CONSOLE execution contexts.
 *
 * HTTP requests use the config default (ah_runtime); the few trusted HTTP paths
 * that need a bypass swap themselves via the `db.system` middleware. Console is
 * decided here, once per process at boot:
 *
 *   - testing env            -> owner  (suite migrates + factories need full access)
 *   - migrate / seed / schema -> owner  (DDL + privilege grants)
 *   - everything else        -> system (queue worker, scheduler, tinker, custom
 *                                       commands — trusted, may touch RLS tables
 *                                       with no per-user context)
 *
 * Registered after DatabaseServiceProvider so encryption keys are already in
 * config. No DB::purge is needed: connections are not opened this early in boot.
 */
class RuntimeDatabaseRoleProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return; // Web: leave the config default (ah_runtime).
        }

        if ($this->app->environment('testing')) {
            ConnectionRole::useOwner();
            return;
        }

        if ($this->isSchemaCommand($_SERVER['argv'][1] ?? '')) {
            ConnectionRole::useOwner();
            return;
        }

        ConnectionRole::useSystem();
    }

    private function isSchemaCommand(string $command): bool
    {
        return str_starts_with($command, 'migrate')
            || str_starts_with($command, 'schema:')
            || in_array($command, ['db:seed', 'db:wipe'], true);
    }
}
