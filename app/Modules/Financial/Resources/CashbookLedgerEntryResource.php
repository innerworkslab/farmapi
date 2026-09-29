<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashbookLedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cashbook_id' => $this->cashbook_id,
            'cashbook_transaction_id' => $this->cashbook_transaction_id,
            'reversal_of_entry_id' => $this->reversal_of_entry_id,
            'reference' => $this->reference,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'source_type' => $this->source_type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'running_balance' => $this->running_balance,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
