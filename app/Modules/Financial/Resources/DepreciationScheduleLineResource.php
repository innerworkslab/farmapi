<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationScheduleLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'depreciation_id' => $this->depreciation_id,
            'sequence_number' => $this->sequence_number,
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount,
            'status' => $this->status,
            'cashbook_transaction_id' => $this->cashbook_transaction_id,
            'cashbook_posting_reference' => $this->whenLoaded('cashbookTransaction', fn () => $this->cashbookTransaction?->reference),
            'posted_by_id' => $this->posted_by_id,
            'posted_at' => $this->posted_at?->toISOString(),
            'failure_reason' => $this->failure_reason,
            'reversed_by_id' => $this->reversed_by_id,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
