<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class IdentitySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Database\Seeders\Identity\RoleSeeder::class,
            \Database\Seeders\Identity\PermissionSeeder::class,
            \Database\Seeders\Identity\TestUserSeeder::class,
        ]);
    }
}
