<?php

namespace Database\Seeders;

use App\Modules\Setup\Services\FarmReceivingUomService;
use Illuminate\Database\Seeder;

class FarmReceivingUomSeeder extends Seeder
{
    public function run(): void
    {
        app(FarmReceivingUomService::class)->defaultUnit();
    }
}
