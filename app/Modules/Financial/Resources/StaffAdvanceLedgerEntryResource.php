<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAdvanceLedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_advance_id' => $this->staff_advance_id,
            'staff_advance_repayment_id' => $this->staff_advance_repayment_id,
            'cashbook_transaction_id' => $this->cashbook_transaction_id,
            'reversal_of_entry_id' => $this->reversal_of_entry_id,
            'reference' => $this->reference,
            'business_date' => $this->business_date?->toDateString(),
            'entry_type' => $this->entry_type,
            'effect' => $this->effect,
            'addition' => $this->effect === 'addition' ? $this->amount : '0.00',
            'deduction' => $this->effect === 'deduction' ? $this->amount : '0.00',
            'amount' => $this->amount,
            'currency_code' => $this->currency_code,
            'running_balance' => $this->running_balance,
            'description' => $this->description,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
