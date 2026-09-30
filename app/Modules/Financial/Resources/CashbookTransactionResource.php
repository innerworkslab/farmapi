<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashbookTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cashbook_id' => $this->cashbook_id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'direction' => $this->category->direction,
            ] : null),
            'cashbook' => $this->whenLoaded('cashbook', fn () => [
                'id' => $this->cashbook->id,
                'name' => $this->cashbook->name,
                'type' => $this->cashbook->type,
                'currency_code' => $this->cashbook->currency_code,
            ]),
            'reference' => $this->reference,
            'external_reference' => $this->external_reference,
            'business_date' => $this->business_date?->toDateString(),
            'direction' => $this->direction,
            'amount' => $this->amount,
            'description' => $this->description,
            'source_type' => $this->source_type,
            'status' => $this->status,
            'ledger_entry_id' => $this->whenLoaded('ledgerEntry', fn () => $this->ledgerEntry?->id),
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'reversed_by_transaction_id' => $this->whenLoaded('reversedByTransaction', fn () => $this->reversedByTransaction?->id),
            'reversal_reason' => $this->reversal_reason,
            'created_by_id' => $this->created_by_id,
            'confirmed_by_id' => $this->confirmed_by_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'reversed_by_id' => $this->reversed_by_id,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'version' => $this->version,
        ];
    }
}
