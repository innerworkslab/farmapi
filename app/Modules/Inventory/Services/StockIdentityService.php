<?php

namespace App\Modules\Inventory\Services;

class StockIdentityService
{
    /** @param array<string, mixed> $attributes */
    public function key(array $attributes): string
    {
        $identity = [
            'category' => $attributes['category'] ?? null,
            'item_type' => $attributes['item_type'] ?? null,
            'item_id' => $attributes['item_id'] ?? null,
            'branch_id' => $attributes['branch_id'] ?? null,
            'inventory_id' => $attributes['inventory_id'] ?? null,
            'farm_information_id' => $attributes['farm_information_id'] ?? null,
            'location' => $attributes['location'] ?? 'MAIN',
            'stock_uom_id' => $attributes['stock_uom_id'] ?? null,
            'stock_lot_id' => $attributes['stock_lot_id'] ?? null,
            'equipment_instance_id' => $attributes['equipment_instance_id'] ?? null,
        ];

        ksort($identity);

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
