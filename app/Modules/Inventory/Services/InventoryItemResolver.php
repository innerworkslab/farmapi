<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InventoryItemResolver
{
    /** @return array{item: Item, model: Model, item_type: string, stock_uom_id: int|null, batch_tracking: bool, expiry_tracking: bool, serial_tracking: bool, asset_tracking: bool, cold_chain_required: bool, conversion_factor: float} */
    public function resolve(string $category, int $itemId): array
    {
        $category = $this->normalizeCategory($category);

        $item = Item::query()->with('itemable')->find($itemId);
        if (! $item) {
            throw ValidationException::withMessages([
                'item_id' => 'The selected inventory item does not exist.',
            ]);
        }

        if ($item->category !== $category) {
            throw ValidationException::withMessages([
                'item_id' => "The selected item is not a {$category} item.",
            ]);
        }

        if ($item->status !== 'active') {
            throw ValidationException::withMessages([
                'item_id' => 'The selected inventory item is inactive.',
            ]);
        }

        if (! $item->itemable) {
            throw ValidationException::withMessages([
                'item_id' => 'The selected inventory item is not linked to an active master record.',
            ]);
        }

        return [
            'item' => $item,
            'model' => $item->itemable,
            'item_type' => $item->category,
            'stock_uom_id' => $item->stock_uom_id,
            'batch_tracking' => (bool) $item->batch_tracking,
            'expiry_tracking' => (bool) $item->expiry_tracking,
            'serial_tracking' => (bool) $item->serial_tracking,
            'asset_tracking' => (bool) $item->asset_tracking,
            'cold_chain_required' => (bool) $item->cold_chain_required,
            'conversion_factor' => (float) ($item->uom_conversion ?? 1),
        ];
    }

    public function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));

        if (! in_array($category, ['food', 'medicine', 'equipment'], true)) {
            throw ValidationException::withMessages([
                'category' => 'Category must be food, medicine, or equipment.',
            ]);
        }

        return $category;
    }
}