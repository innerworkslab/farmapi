<?php

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->status,
            'posting_batch_id' => $this->posting_batch_id,
            'notes' => $this->notes,
            'version' => $this->version,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'lines' => PurchaseReceiptLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}