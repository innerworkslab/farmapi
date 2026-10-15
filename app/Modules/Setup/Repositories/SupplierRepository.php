<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class SupplierRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'phone_number', 'contact_person'];
    }

    protected function filterColumns(): array
    {
        return ['payment_terms' => 'payment_terms', 'preferred_branch_id' => 'preferred_branch_id', 'status' => 'status'];
    }

    protected function relations(): array
    {
        return ['preferredBranch', 'types'];
    }

    protected function applyCustomFilters(Builder $query, array $filters): void
    {
        $types = $filters['types'] ?? ($filters['type'] ?? null);
        if ($types === null || $types === '') {
            return;
        }

        $types = is_array($types) ? $types : [$types];
        $query->where(function (Builder $query) use ($types): void {
            $query->whereHas('types', fn ($typeQuery) => $typeQuery->whereIn('type', $types))
                ->orWhereIn('type', $types);
        });
    }
}
