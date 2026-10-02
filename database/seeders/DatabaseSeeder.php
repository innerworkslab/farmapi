<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Database\Seeders\Demo\DemoAuthSeeder;
use Database\Seeders\Demo\DemoBusinessMasterSeeder;
use Database\Seeders\Demo\DemoDepreciationPermissionsSeeder;
use Database\Seeders\Demo\DemoInventorySeeder;
use Database\Seeders\Demo\DemoFinancialSeeder;
use Database\Seeders\Demo\DemoFinancialCategoryPermissionsSeeder;
use Database\Seeders\Demo\DemoStaffPermissionsSeeder;
use Database\Seeders\Demo\DemoStaffAdvancePermissionsSeeder;
use Database\Seeders\Demo\DemoPurchasingSeeder;
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
            DemoFinancialCategoryPermissionsSeeder::class,
            DemoStaffPermissionsSeeder::class,
            DemoStaffAdvancePermissionsSeeder::class,
            DemoDepreciationPermissionsSeeder::class,
            DemoSetupSeeder::class,
            DemoBusinessMasterSeeder::class,
            DemoInventorySeeder::class,
            DemoPurchasingSeeder::class,
            DemoFinancialSeeder::class,
        ]);
    }
}
