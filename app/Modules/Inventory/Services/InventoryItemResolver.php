<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Medicine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InventoryItemResolver
{
    /** @return array{model: Model, item_type: string, stock_uom_id: int|null, batch_tracking: bool, expiry_tracking: bool, cold_chain_required: bool, conversion_factor: float} */
    public function resolve(string $category, int $itemId): array
    {
        $category = $this->normalizeCategory($category);

        $model = match ($category) {
            'food' => Food::query()->find($itemId),
            'medicine' => Medicine::query()->find($itemId),
            'equipment' => Equipment::query()->find($itemId),
            default => null,
        };

        if (! $model) {
            throw ValidationException::withMessages([
                'item_id' => "The selected {$category} item does not exist.",
            ]);
        }

        if (isset($model->status) && $model->status !== 'active') {
            throw ValidationException::withMessages([
                'item_id' => "The selected {$category} item is inactive.",
            ]);
        }

        return [
            'model' => $model,
            'item_type' => $category,
            'stock_uom_id' => $model->stock_uom_id ?? null,
            'batch_tracking' => (bool) ($model->batch_tracking ?? false),
            'expiry_tracking' => (bool) ($model->expiry_tracking ?? false),
            'cold_chain_required' => (bool) ($model->cold_chain_required ?? false),
            'conversion_factor' => (float) ($model->uom_conversion ?? 1),
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
