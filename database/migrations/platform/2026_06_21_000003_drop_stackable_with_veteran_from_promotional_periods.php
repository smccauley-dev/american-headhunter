<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE promotional_periods DROP COLUMN IF EXISTS stackable_with_veteran;'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE promotional_periods ADD COLUMN stackable_with_veteran BOOLEAN NOT NULL DEFAULT true;'
        );
    }
};
