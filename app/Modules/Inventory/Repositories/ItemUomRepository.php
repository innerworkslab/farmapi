<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ItemUomRepository
{
    public function lockForMaster(Model $master): ?Item
    {
        return Item::withTrashed()->where('itemable_type', $master::class)
            ->where('itemable_id', $master->getKey())->lockForUpdate()->first();
    }

    public function hasReferences(Item $item): bool
    {
        foreach (['inventory_balances', 'inventory_ledger_entries', 'purchase_invoice_lines', 'inventory_adjustment_lines', 'inventory_stock_lots'] as $table) {
            if (DB::table($table)->where('item_id', $item->id)->exists()) {
                return true;
            }
        }
        foreach (['farm_feeding_lines', 'farm_feeding_plans'] as $table) {
            if (DB::table($table)->where('food_item_id', $item->id)->exists()) {
                return true;
            }
        }

        // This fallback runs only on definition edits; JSON object containment differs by database driver.
        foreach (DB::table('farm_operations')->where('type', 'medication')->select(['id', 'payload'])->lazyById() as $operation) {
            foreach (json_decode($operation->payload, true, 512, JSON_THROW_ON_ERROR)['lines'] ?? [] as $line) {
                if ((int) ($line['medicine_item_id'] ?? 0) === (int) $item->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
