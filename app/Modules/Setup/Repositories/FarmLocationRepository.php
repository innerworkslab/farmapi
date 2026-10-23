<?php

namespace App\Modules\Setup\Repositories;

use App\Models\User;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\HouseBarn;
use App\Modules\Setup\Models\PenCagePond;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Model;

class FarmLocationRepository
{
    public function query(string $kind)
    {
        return match ($kind) {
            'house-barns' => HouseBarn::query(),
            'pen-cage-ponds' => PenCagePond::query(),
            default => abort(404),
        };
    }

    public function find(string $kind, int $id, bool $lock = false): Model
    {
        return $this->query($kind)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function paginate(string $kind, array $filters, User $actor)
    {
        return BranchAccess::scope($this->query($kind), $actor)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($kind === 'pen-cage-ponds' && isset($filters['house_barn_id']), fn ($q) => $q->where('house_barn_id', $filters['house_barn_id']))
            ->when($kind === 'pen-cage-ponds' && ($filters['status'] ?? null) === 'active', fn ($q) => $q->where(fn ($q) => $q->whereNull('house_barn_id')->orWhereHas('houseBarn', fn ($q) => $q->where('status', 'active'))))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 15);
    }

    public function isReferenced(string $kind, int $id): bool
    {
        return FarmInformation::withTrashed()->where($kind === 'house-barns' ? 'house_barn_id' : 'pen_cage_pond_id', $id)->exists()
            || ($kind === 'house-barns' && PenCagePond::withTrashed()->where('house_barn_id', $id)->exists());
    }
}
