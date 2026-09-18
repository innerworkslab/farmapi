<?php

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'category' => $this->category,
            'item_id' => $this->item_id,
            'description' => $this->description,
            'purchase_uom_id' => $this->purchase_uom_id,
            'stock_uom_id' => $this->stock_uom_id,
            'conversion_factor' => $this->conversion_factor,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->gross_amount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'foc_type' => $this->foc_type,
            'foc_value' => $this->foc_value,
            'foc_quantity' => $this->foc_quantity,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'total_expected_quantity' => $this->total_expected_quantity,
            'received_quantity' => $this->received_quantity,
            'received_foc_quantity' => $this->received_foc_quantity,
            'target_inventory_id' => $this->target_inventory_id,
            'target_farm_information_id' => $this->target_farm_information_id,
            'target_location' => $this->target_location,
            'metadata' => $this->metadata,
        ];
    }
}