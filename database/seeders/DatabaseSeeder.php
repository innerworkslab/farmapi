<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Database\Seeders\Demo\DemoAuthSeeder;
use Database\Seeders\Demo\DemoBusinessMasterSeeder;
use Database\Seeders\Demo\DemoInventorySeeder;
use Database\Seeders\Demo\DemoSetupSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DemoAuthSeeder::class,
            DemoSetupSeeder::class,
            DemoBusinessMasterSeeder::class,
            DemoInventorySeeder::class,
        ]);
    }
}
