<?php

namespace App\Modules\Farms\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedingLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'food_item_id' => $this->food_item_id,
            'food' => $this->foodItem ? [
                'id' => $this->foodItem->id,
                'code' => $this->foodItem->code,
                'name' => $this->foodItem->name,
                'category' => $this->foodItem->master_category,
            ] : null,
            'inventory_id' => $this->inventory_id,
            'stock_lot_id' => $this->stock_lot_id,
            'stock_lot' => $this->stockLot ? [
                'id' => $this->stockLot->id,
                'receipt_lot_number' => $this->stockLot->receipt_lot_number,
                'supplier_batch_number' => $this->stockLot->supplier_batch_number,
                'expiry_date' => $this->stockLot->expiry_date?->toDateString(),
                'lot_status' => $this->stockLot->lot_status,
            ] : null,
            'source_location' => $this->source_location,
            'stock_uom_id' => $this->stock_uom_id,
            'quantity' => (float) $this->quantity,
            'wastage_quantity' => (float) $this->wastage_quantity,
            'quantity_per_animal' => (float) $this->quantity_per_animal,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}