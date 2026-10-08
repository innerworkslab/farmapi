<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class BranchAccess
{
    public static function scope(Builder $query, User $actor, string $column = 'branch_id'): Builder
    {
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn($column, $actor->branches()->select('branches.id'));
        }

        return $query;
    }

    public static function authorize(User $actor, ?int $branchId): void
    {
        abort_unless($branchId && ($actor->hasRole('super-admin') || $actor->branches()->whereKey($branchId)->exists()), 403, 'You are not authorized to access this branch.');
    }

    public static function locations(User $actor, int $branchId, ?int $inventoryId = null, ?int $farmId = null): void
    {
        self::authorize($actor, $branchId);
        foreach (['inventory_id' => [Inventory::class, $inventoryId], 'farm_information_id' => [FarmInformation::class, $farmId]] as $field => [$model, $id]) {
            if ($id === null) {
                continue;
            }
            $location = $model::query()->findOrFail($id);
            self::authorize($actor, $location->branch_id);
            if ((int) $location->branch_id !== $branchId) {
                throw ValidationException::withMessages([$field => 'The location must belong to the transaction branch.']);
            }
        }
    }
}
