<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'posting_id' => $this->posting_id,
            'posting_batch_id' => $this->posting_batch_id,
            'confirmation_id' => $this->confirmation_id,
            'source_module' => $this->source_module,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_line_id' => $this->source_line_id,
            'transaction_type' => $this->transaction_type,
            'direction' => $this->direction,
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
            'original_quantity' => $this->original_quantity,
            'original_uom_id' => $this->original_uom_id,
            'conversion_factor' => $this->conversion_factor,
            'quantity_in' => $this->quantity_in,
            'quantity_out' => $this->quantity_out,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'posting_status' => $this->posting_status,
            'posted_by_id' => $this->posted_by_id,
            'posted_at' => $this->posted_at?->toISOString(),
            'metadata' => $this->metadata,
        ];
    }
}
