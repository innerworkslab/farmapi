<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'category' => $this->category,
            'item_type' => $this->item_type,
            'item_id' => $this->item_id,
            'location' => $this->location,
            'stock_uom_id' => $this->stock_uom_id,
            'stock_lot_id' => $this->stock_lot_id,
            'equipment_instance_id' => $this->equipment_instance_id,
            'system_quantity' => $this->system_quantity,
            'counted_quantity' => $this->counted_quantity,
            'adjustment_quantity' => $this->adjustment_quantity,
            'direction' => $this->direction,
            'new_identity' => $this->new_identity,
            'receipt_lot_number' => $this->receipt_lot_number,
            'supplier_batch_number' => $this->supplier_batch_number,
            'manufacturing_date' => $this->manufacturing_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'lot_status' => $this->lot_status,
            'serial_number' => $this->serial_number,
            'asset_tag' => $this->asset_tag,
            'metadata' => $this->metadata,
        ];
    }
}
