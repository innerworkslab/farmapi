<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InventoryBalanceService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = BranchAccess::scope(InventoryBalance::query(), $actor)
            ->with(['inventory', 'stockLot', 'equipmentInstance', 'stockUom'])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['item_id'] ?? null, fn (Builder $query, string $itemId) => $query->where('item_id', $itemId))
            ->when($filters['inventory_id'] ?? null, fn (Builder $query, string $inventoryId) => $query->where('inventory_id', $inventoryId))
            ->when($filters['location'] ?? null, fn (Builder $query, string $location) => $query->where('location', $location))
            ->when($filters['available_only'] ?? null, fn (Builder $query) => $query->where('available_quantity', '>', 0))
            ->orderBy('category')
            ->orderBy('item_id')
            ->orderBy('location');

        $movement = DB::table('inventory_ledger_entries')
            ->whereColumn('inventory_ledger_entries.identity_key', 'inventory_balances.identity_key')
            ->where('posting_status', 'posted');
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;
        $query->addSelect(['inventory_balances.*']);
        if ($from) {
            $opening = clone $movement;
            $opening->where('posted_at', '<', $from.' 00:00:00');
            $query->selectSub($opening->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0)'), 'period_opening_quantity');
        } else {
            $query->selectRaw('0 as period_opening_quantity');
        }
        foreach (['in' => 'quantity_in', 'out' => 'quantity_out'] as $key => $column) {
            $subquery = clone $movement;
            if ($from) {
                $subquery->where('posted_at', '>=', $from.' 00:00:00');
            }
            if ($to) {
                $subquery->where('posted_at', '<', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00');
            }
            $query->selectSub($subquery->selectRaw("COALESCE(SUM({$column}), 0)"), 'period_'.$key.'_quantity');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }
}
