<?php

namespace App\Modules\Farms\Repositories;

use App\Models\User;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

class FarmOperationRepository
{
    public function visible(User $actor, array $filters = []): Builder
    {
        return BranchAccess::scope(FarmOperation::query(), $actor)->with('targets')
            ->when($filters['farm_information_id'] ?? null, fn (Builder $q, $id) => $q->where('farm_information_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            ->when($filters['workflow_state'] ?? null, fn (Builder $q, $state) => $q->where('workflow_state', $state))
            ->when($filters['animal_balance_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('targets', fn (Builder $t) => $t->where('animal_balance_id', $id)))
            ->when($filters['from_date'] ?? null, fn (Builder $q, $date) => $q->whereDate('activity_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, $date) => $q->whereDate('activity_date', '<=', $date));
    }

    public function lock(FarmOperation $operation, User $actor): FarmOperation
    {
        return $this->visible($actor)->lockForUpdate()->findOrFail($operation->id);
    }

    public function lockAnimals(array $ids, int $farmId, User $actor)
    {
        return BranchAccess::scope(InventoryBalance::query(), $actor)
            ->with(['item.itemable', 'profile'])->where('category', 'animal')->where('farm_information_id', $farmId)
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }
}
