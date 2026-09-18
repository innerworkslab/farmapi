<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryLedgerService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return InventoryLedgerEntry::query()
            ->with(['inventory', 'stockLot', 'equipmentInstance', 'stockUom'])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['item_id'] ?? null, fn (Builder $query, string $itemId) => $query->where('item_id', $itemId))
            ->when($filters['inventory_id'] ?? null, fn (Builder $query, string $inventoryId) => $query->where('inventory_id', $inventoryId))
            ->when($filters['transaction_type'] ?? null, fn (Builder $query, string $type) => $query->where('transaction_type', $type))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $type) => $query->where('source_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('posted_at', '<=', $date))
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }
}
