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
            ->orWhereHas('houseBarns', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orWhereHas('penCagePonds', fn ($q) => $q->where('name', 'like', "%{$search}%"));
    }

    protected function filterColumns(): array
    {
        return ['branch_id' => 'branch_id', 'current_animal_id' => 'current_animal_id', 'responsible_employee_id' => 'responsible_employee_id'];
    }

    protected function applyCustomFilters(Builder $query, array $filters): void
    {
        if (isset($filters['house_barn_id'])) {
            $query->whereHas('houseBarns', fn (Builder $barns) => $barns->whereKey($filters['house_barn_id']));
        }
        if (isset($filters['pen_cage_pond_id'])) {
            $query->whereHas('penCagePonds', fn (Builder $pens) => $pens->whereKey($filters['pen_cage_pond_id']));
        }
    }

    protected function relations(): array
    {
        return ['houseBarns', 'penCagePonds', 'branch', 'currentAnimal', 'responsibleEmployee'];
    }
}
