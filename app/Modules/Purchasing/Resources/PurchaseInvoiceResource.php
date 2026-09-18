<?php

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'supplier_id' => $this->supplier_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'delivery_status' => $this->delivery_status,
            'gross_amount' => $this->gross_amount,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'total_expected_quantity' => $this->total_expected_quantity,
            'total_received_quantity' => $this->total_received_quantity,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'version' => $this->version,
            'lines' => PurchaseInvoiceLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}