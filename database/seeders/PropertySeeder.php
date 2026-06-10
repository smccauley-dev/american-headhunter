<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Database\Seeders\Property\SamplePropertySeeder::class,
        ]);
    }
}
