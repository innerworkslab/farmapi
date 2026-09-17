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
        return ['code', 'name', 'ear_tag_rfid_number', 'batch_flock_number', 'breed'];
    }

    protected function filterColumns(): array
    {
        return ['breed' => 'breed', 'category' => 'category', 'gender' => 'gender', 'tracking_type' => 'tracking_type', 'type' => 'type'];
    }

    protected function relations(): array
    {
        return [];
    }
}