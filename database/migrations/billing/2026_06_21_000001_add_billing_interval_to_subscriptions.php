<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        // Record whether a subscription bills monthly or annually. Previously the
        // interval was only used to compute current_period_end at creation and then
        // discarded, so the membership card could not tell an annual member from a
        // monthly one (it always showed the monthly price when a plan had both).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE subscriptions
                ADD COLUMN billing_interval VARCHAR(10) NULL
                    CHECK (billing_interval IN ('monthly', 'annual'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('ALTER TABLE subscriptions DROP COLUMN IF EXISTS billing_interval;');
    }
};
