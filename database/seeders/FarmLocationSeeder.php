<?php

namespace Database\Seeders;

use App\Modules\Setup\Models\HouseBarn;
use App\Modules\Setup\Models\PenCagePond;
use App\Modules\Setup\Services\AnimalClassificationService;
use Illuminate\Database\Seeder;

class FarmLocationSeeder extends Seeder
{
    public function run(): void
    {
        $branch = \App\Modules\Setup\Models\Branch::where('code', 'BR-YGN')->first();
        if ($branch) {
            self::references($branch->id, 'Barn A', 'Pen 1');
            self::references($branch->id, 'Demo Barn', 'Demo Pen');
        }
    }

    public static function references(int $branchId, string $barnName, ?string $penName = null): array
    {
        $barn = HouseBarn::firstOrCreate(['branch_id' => $branchId, 'normalized_name' => AnimalClassificationService::normalize($barnName)], ['name' => $barnName, 'status' => 'active']);
        $pen = $penName ? PenCagePond::firstOrCreate(['branch_id' => $branchId, 'normalized_name' => AnimalClassificationService::normalize($penName)], ['house_barn_id' => $barn->id, 'name' => $penName, 'status' => 'active']) : null;
        if ($pen && (int) $pen->house_barn_id !== (int) $barn->id) {
            throw new \RuntimeException('The demo pen name is already assigned to another barn in this branch.');
        }

        return ['house_barn_id' => $barn->id, 'pen_cage_pond_id' => $pen?->id];
    }
}
