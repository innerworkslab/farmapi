<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Database\Eloquent\Builder;

class FarmInformationRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return FarmInformation::class;
    }

    protected function searchColumns(): array
    {
        return ['name'];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where('name', 'like', "%{$search}%")
            ->orWhereHas('houseBarn', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orWhereHas('penCagePond', fn ($q) => $q->where('name', 'like', "%{$search}%"));
    }

    protected function filterColumns(): array
    {
        return ['branch_id' => 'branch_id', 'current_animal_id' => 'current_animal_id', 'house_barn_id' => 'house_barn_id', 'pen_cage_pond_id' => 'pen_cage_pond_id', 'responsible_employee_id' => 'responsible_employee_id'];
    }

    protected function relations(): array
    {
        return ['houseBarn', 'penCagePond', 'branch', 'currentAnimal', 'responsibleEmployee'];
    }
}
