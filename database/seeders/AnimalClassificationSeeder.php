<?php

namespace Database\Seeders;

use App\Modules\Setup\Models\AnimalBreed;
use App\Modules\Setup\Models\AnimalCategory;
use App\Modules\Setup\Models\AnimalType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnimalClassificationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $type = AnimalType::withTrashed()->firstOrCreate(['normalized_name' => 'cattle'], ['name' => 'cattle', 'status' => 'active']);
            AnimalCategory::withTrashed()->firstOrCreate(['animal_type_id' => $type->id, 'normalized_name' => 'beef'], ['name' => 'beef', 'status' => 'active']);
            foreach (['Brahman', 'Brahman Cross'] as $breed) {
                AnimalBreed::withTrashed()->firstOrCreate(['animal_type_id' => $type->id, 'normalized_name' => mb_strtolower($breed)], ['name' => $breed, 'status' => 'active']);
            }
        });
    }

    public static function cattleReferences(string $breed = 'brahman'): array
    {
        $type = AnimalType::where('normalized_name', 'cattle')->firstOrFail();

        return [
            'animal_type_id' => $type->id,
            'animal_category_id' => AnimalCategory::where('animal_type_id', $type->id)->where('normalized_name', 'beef')->firstOrFail()->id,
            'animal_breed_id' => AnimalBreed::where('animal_type_id', $type->id)->where('normalized_name', $breed)->firstOrFail()->id,
        ];
    }
}
