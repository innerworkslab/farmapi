<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryBalance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryBalanceService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return InventoryBalance::query()
            ->with(['inventory', 'stockLot', 'equipmentInstance', 'stockUom'])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['item_id'] ?? null, fn (Builder $query, string $itemId) => $query->where('item_id', $itemId))
            ->when($filters['inventory_id'] ?? null, fn (Builder $query, string $inventoryId) => $query->where('inventory_id', $inventoryId))
            ->when($filters['location'] ?? null, fn (Builder $query, string $location) => $query->where('location', $location))
            ->when($filters['available_only'] ?? null, fn (Builder $query) => $query->where('available_quantity', '>', 0))
            ->orderBy('category')
            ->orderBy('item_id')
            ->orderBy('location')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }
}
