<?php

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\Financial\Support\Money;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : collect();
        $paid = $payments->where('state', 'confirmed')->sum(fn ($p) => Money::minor($p->amount));
        $pending = $payments->where('state', 'draft')->sum(fn ($p) => Money::minor($p->amount));

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'currency_code' => $this->currency_code,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'supplier_id' => $this->supplier_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'delivery_status' => $this->delivery_status,
            'gross_amount' => $this->gross_amount,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'cashbook_id' => $this->whenLoaded('payments', fn () => $payments->firstWhere('idempotency_key', \App\Modules\Purchasing\Services\PurchasePaymentService::initialKey($this->id))?->cashbook_id),
            'paid_amount' => $this->whenLoaded('payments', fn () => Money::decimal($paid)),
            'pending_paid_amount' => $this->whenLoaded('payments', fn () => Money::decimal($pending)),
            'outstanding_amount' => $this->whenLoaded('payments', fn () => Money::decimal($this->status === 'cancelled' ? 0 : Money::minor($this->total_amount) - $paid)),
            'payments' => $this->whenLoaded('payments', fn () => $payments->map(fn ($p) => [
                'id' => $p->id, 'cashbook_id' => $p->cashbook_id, 'amount' => $p->amount, 'state' => $p->state,
                'cashbook_transaction_id' => $p->cashbook_transaction_id, 'confirmed_at' => $p->confirmed_at?->toISOString(),
            ])->values()),
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
