<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Medicine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ItemRegistryService
{
    public function syncFromMaster(Model $master): Item
    {
        $data = $this->payloadFor($master);

        $item = Item::withTrashed()->updateOrCreate(
            [
                'itemable_type' => $master::class,
                'itemable_id' => $master->getKey(),
            ],
            $data
        );

        if ($item->trashed() && ($data['status'] ?? null) === 'active') {
            $item->restore();
        }

        return $item->refresh();
    }

    public function deactivateFromMaster(Model $master): ?Item
    {
        $item = Item::query()
            ->where('itemable_type', $master::class)
            ->where('itemable_id', $master->getKey())
            ->first();

        if (! $item) {
            return null;
        }

        $item->fill([
            'status' => 'inactive',
            'version' => $item->version + 1,
        ])->save();

        return $item->refresh();
    }

    /** @return array<string, mixed> */
    private function payloadFor(Model $master): array
    {
        if ($master instanceof Food) {
            return [
                'code' => $master->code,
                'name' => $master->name,
                'category' => 'food',
                'master_category' => $master->category,
                'stock_uom_id' => $master->stock_uom_id,
                'purchase_uom_id' => $master->purchase_uom_id,
                'usage_uom_id' => $master->consumption_uom_id,
                'uom_conversion' => $master->uom_conversion ?? 1,
                'batch_tracking' => (bool) $master->batch_tracking,
                'expiry_tracking' => (bool) $master->expiry_tracking,
                'serial_tracking' => false,
                'asset_tracking' => false,
                'cold_chain_required' => false,
                'divisible_quantity' => true,
                'minimum_stock_level' => $master->minimum_stock_level ?? 0,
                'maximum_stock_level' => $master->maximum_stock_level,
                'reorder_level' => $master->reorder_level ?? 0,
                'reorder_quantity' => $master->reorder_quantity ?? 0,
                'status' => $master->status ?? 'active',
                'source_version' => $master->version ?? 1,
            ];
        }

        if ($master instanceof Medicine) {
            return [
                'code' => $master->code,
                'name' => $master->name,
                'category' => 'medicine',
                'master_category' => $master->category,
                'stock_uom_id' => $master->stock_uom_id,
                'purchase_uom_id' => $master->purchase_uom_id,
                'usage_uom_id' => $master->usage_uom_id,
                'uom_conversion' => $master->uom_conversion ?? 1,
                'batch_tracking' => (bool) $master->batch_tracking,
                'expiry_tracking' => (bool) $master->expiry_tracking,
                'serial_tracking' => false,
                'asset_tracking' => false,
                'cold_chain_required' => (bool) $master->cold_chain_required,
                'divisible_quantity' => true,
                'minimum_stock_level' => $master->minimum_stock_level ?? 0,
                'maximum_stock_level' => $master->maximum_stock_level,
                'reorder_level' => $master->reorder_level ?? 0,
                'reorder_quantity' => $master->reorder_quantity ?? 0,
                'status' => $master->status ?? 'active',
                'source_version' => $master->version ?? 1,
            ];
        }

        if ($master instanceof Equipment) {
            return [
                'code' => $master->code,
                'name' => $master->name,
                'category' => 'equipment',
                'master_category' => $master->category,
                'stock_uom_id' => null,
                'purchase_uom_id' => null,
                'usage_uom_id' => null,
                'uom_conversion' => 1,
                'batch_tracking' => false,
                'expiry_tracking' => false,
                'serial_tracking' => (bool) $master->serial_number,
                'asset_tracking' => true,
                'cold_chain_required' => false,
                'divisible_quantity' => false,
                'minimum_stock_level' => 0,
                'maximum_stock_level' => null,
                'reorder_level' => 0,
                'reorder_quantity' => 0,
                'status' => $master->status ?? 'active',
                'source_version' => $master->version ?? 1,
            ];
        }

        throw ValidationException::withMessages([
            'itemable_type' => 'Only food, medicine, and equipment masters can be registered as inventory items.',
        ]);
    }
}
