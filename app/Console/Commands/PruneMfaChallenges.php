<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMfaChallenges extends Command
{
    protected $signature   = 'mfa:prune-challenges';
    protected $description = 'Delete consumed and long-expired rows from mfa_challenges.';

    public function handle(): int
    {
        // Two categories are safe to delete:
        //   used_at IS NOT NULL  — challenge was verified and consumed; never needed again
        //   expires_at < 7 days ago — expired without being used; 7-day window retained
        //     for incident investigation (e.g. confirming when an attack attempt occurred)
        $deleted = DB::connection('identity')
            ->table('mfa_challenges')
            ->where(function ($q) {
                $q->whereNotNull('used_at')
                  ->orWhere('expires_at', '<', now()->subDays(7));
            })
            ->delete();

        $this->info("mfa:prune-challenges: deleted {$deleted} rows.");

        return Command::SUCCESS;
    }
}
