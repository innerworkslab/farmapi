<?php

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_invoice_line_id' => $this->purchase_invoice_line_id,
            'line_number' => $this->line_number,
            'category' => $this->category,
            'item_id' => $this->item_id,
            'accepted_quantity' => $this->accepted_quantity,
            'accepted_foc_quantity' => $this->accepted_foc_quantity,
            'rejected_quantity' => $this->rejected_quantity,
            'rejection_reason' => $this->rejection_reason,
            'target_inventory_id' => $this->target_inventory_id,
            'target_farm_information_id' => $this->target_farm_information_id,
            'target_location' => $this->target_location,
            'purchase_uom_id' => $this->purchase_uom_id,
            'stock_uom_id' => $this->stock_uom_id,
            'conversion_factor' => $this->conversion_factor,
            'supplier_batch_number' => $this->supplier_batch_number,
            'receipt_lot_number' => $this->receipt_lot_number,
            'manufacturing_date' => $this->manufacturing_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'cold_chain_required' => $this->cold_chain_required,
            'cold_chain_status' => $this->cold_chain_status,
            'observed_temperature' => $this->observed_temperature,
            'temperature_uom' => $this->temperature_uom,
            'exception_reason' => $this->exception_reason,
            'metadata' => $this->metadata,
        ];
    }
}