<?php

namespace Database\Seeders\Billing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dev-only subscriptions so the member profile "My Membership" panel can be
 * exercised across every billing state. Maps a handful of test users to the
 * current (non-superseded) version of a paid plan for their account type;
 * everyone else is left to resolve to their free tier.
 *
 * Idempotent: skips a user who already has any subscription row.
 */
class TestSubscriptionSeeder extends Seeder
{
    /** email => [plan_key, status] */
    private array $assignments = [
        'hunter@test.local'    => ['hunter_pro',     'active'],
        'landowner@test.local' => ['landowner_ranch', 'trialing'],
        'club@test.local'      => ['club_premium',   'past_due'],
    ];

    public function run(): void
    {
        $now = now();

        foreach ($this->assignments as $email => [$planKey, $status]) {
            $userId = DB::connection('identity')->table('users')
                ->where('email', $email)
                ->value('id');

            if (! $userId) {
                $this->command->warn("Skipping {$email} — user not found.");
                continue;
            }

            $alreadySubscribed = DB::connection('billing')->table('subscriptions')
                ->where('user_id', $userId)
                ->exists();

            if ($alreadySubscribed) {
                continue;
            }

            // Current (live) version of the target plan — DB 12 (platform).
            $planVersionId = DB::connection('platform')->table('plan_versions as pv')
                ->join('membership_plans as mp', 'mp.id', '=', 'pv.plan_id')
                ->where('mp.plan_key', $planKey)
                ->whereNull('pv.superseded_at')
                ->value('pv.id');

            if (! $planVersionId) {
                $this->command->warn("Skipping {$email} — no live version for plan {$planKey}.");
                continue;
            }

            DB::connection('billing')->table('subscriptions')->insert([
                'id'                   => (string) Str::uuid(),
                'user_id'              => $userId,
                'plan_version_id'      => $planVersionId,
                'status'               => $status,
                'current_period_start' => $now->toDateString(),
                'current_period_end'   => $now->copy()->addMonth()->toDateString(),
                'trial_ends_at'        => $status === 'trialing' ? $now->copy()->addDays(10) : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            $this->command->info("Subscribed {$email} → {$planKey} ({$status}).");
        }
    }
}
