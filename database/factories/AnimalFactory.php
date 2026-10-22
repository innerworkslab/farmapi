<?php

namespace Database\Factories;

use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\AnimalBreed;
use App\Modules\Setup\Models\AnimalCategory;
use App\Modules\Setup\Models\AnimalType;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnimalFactory extends Factory
{
    protected $model = Animal::class;

    public function definition(): array
    {
        $type = AnimalType::firstOrCreate(['normalized_name' => 'cattle'], ['name' => 'cattle', 'status' => 'active']);
        $category = AnimalCategory::firstOrCreate(['animal_type_id' => $type->id, 'normalized_name' => 'beef'], ['name' => 'beef', 'status' => 'active']);
        $breed = AnimalBreed::firstOrCreate(['animal_type_id' => $type->id, 'normalized_name' => 'brahman'], ['name' => 'Brahman', 'status' => 'active']);

        return [
            'code' => 'ANI-'.fake()->unique()->uuid(), 'tracking_type' => 'batch',
            'batch_flock_number' => fake()->unique()->uuid(), 'name' => 'Test cattle', 'gender' => 'mixed',
            'animal_type_id' => $type->id, 'animal_category_id' => $category->id, 'animal_breed_id' => $breed->id,
        ];
    }
}
