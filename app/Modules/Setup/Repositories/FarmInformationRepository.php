<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\FarmInformation;

class FarmInformationRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return FarmInformation::class;
    }

    protected function searchColumns(): array
    {
        return ['name', 'house_barn', 'pen_cage_pond'];
    }

    protected function filterColumns(): array
    {
        return ['branch_id' => 'branch_id', 'current_animal_id' => 'current_animal_id', 'house_barn' => 'house_barn', 'responsible_employee_id' => 'responsible_employee_id'];
    }

    protected function relations(): array
    {
        return ['branch', 'currentAnimal', 'responsibleEmployee'];
    }
}