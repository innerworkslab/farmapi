<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identity_key' => $this->identity_key,
            'category' => $this->category,
            'item_type' => $this->item_type,
            'item_id' => $this->item_id,
            'branch_id' => $this->branch_id,
            'inventory_id' => $this->inventory_id,
            'farm_information_id' => $this->farm_information_id,
            'location' => $this->location,
            'stock_uom_id' => $this->stock_uom_id,
            'stock_lot_id' => $this->stock_lot_id,
            'equipment_instance_id' => $this->equipment_instance_id,
            'on_hand_quantity' => $this->on_hand_quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'quarantined_quantity' => $this->quarantined_quantity,
            'damaged_quantity' => $this->damaged_quantity,
            'expired_quantity' => $this->expired_quantity,
            'available_quantity' => $this->available_quantity,
            'version' => $this->version,
            'last_ledger_entry_id' => $this->last_ledger_entry_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
