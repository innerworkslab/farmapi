<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\AnimalBreed;
use App\Modules\Setup\Models\AnimalCategory;
use App\Modules\Setup\Models\AnimalType;
use App\Modules\Setup\Models\Medicine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AnimalClassificationRepository
{
    public function modelClass(string $kind): string
    {
        return match ($kind) {
            'animal-types' => AnimalType::class,
            'animal-categories' => AnimalCategory::class,
            'animal-breeds' => AnimalBreed::class,
            default => abort(404),
        };
    }

    public function query(string $kind): Builder
    {
        return $this->modelClass($kind)::query();
    }

    public function find(string $kind, int $id, bool $lock = false): Model
    {
        return $this->query($kind)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function paginate(string $kind, array $filters): LengthAwarePaginator
    {
        return $this->query($kind)->when($kind !== 'animal-types', fn ($query) => $query->with('animalType'))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($kind !== 'animal-types' && isset($filters['animal_type_id']), fn ($query) => $query->where('animal_type_id', $filters['animal_type_id']))
            ->when($kind !== 'animal-types' && ($filters['status'] ?? null) === 'active', fn ($query) => $query->whereHas('animalType', fn ($parent) => $parent->where('status', 'active')))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 15);
    }

    public function animalsUsing(string $kind, int $id): Builder
    {
        $field = match ($kind) {
            'animal-types' => 'animal_type_id',
            'animal-categories' => 'animal_category_id',
            'animal-breeds' => 'animal_breed_id',
        };

        return Animal::withTrashed()->where($field, $id);
    }

    public function isReferenced(string $kind, Model $record): bool
    {
        return $this->animalsUsing($kind, $record->id)->exists()
            || ($kind === 'animal-types' && (Medicine::withTrashed()->where('animal_type_id', $record->id)->exists()
                || AnimalCategory::withTrashed()->where('animal_type_id', $record->id)->exists()
                || AnimalBreed::withTrashed()->where('animal_type_id', $record->id)->exists()));
    }
}
