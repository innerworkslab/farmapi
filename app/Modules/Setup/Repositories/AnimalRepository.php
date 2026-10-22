<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Animal;

class AnimalRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Animal::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'ear_tag_rfid_number', 'batch_flock_number'];
    }

    protected function filterColumns(): array
    {
        return ['gender' => 'gender', 'tracking_type' => 'tracking_type', 'animal_type_id' => 'animal_type_id', 'animal_category_id' => 'animal_category_id', 'animal_breed_id' => 'animal_breed_id'];
    }

    protected function relations(): array
    {
        return ['animalType', 'animalCategory', 'animalBreed'];
    }
}
