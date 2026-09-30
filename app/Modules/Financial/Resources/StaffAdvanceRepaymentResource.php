<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAdvanceRepaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_advance_id' => $this->staff_advance_id,
            'advance_reference' => $this->whenLoaded('advance', fn () => $this->advance?->reference),
            'staff_id' => $this->staff_id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'business_date' => $this->business_date?->toDateString(),
            'cashbook_id' => $this->cashbook_id,
            'cashbook' => $this->whenLoaded('cashbook', fn () => $this->cashbook ? [
                'id' => $this->cashbook->id,
                'name' => $this->cashbook->name,
                'type' => $this->cashbook->type,
                'currency_code' => $this->cashbook->currency_code,
            ] : null),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'direction' => $this->category->direction,
            ] : null),
            'external_reference' => $this->external_reference,
            'description' => $this->description,
            'status' => $this->status,
            'cashbook_transaction_id' => $this->cashbook_transaction_id,
            'cashbook_posting_reference' => $this->whenLoaded('cashbookTransaction', fn () => $this->cashbookTransaction?->reference),
            'confirmed_by_id' => $this->confirmed_by_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_by_id' => $this->cancelled_by_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'reversed_by_id' => $this->reversed_by_id,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'reversal_reason' => $this->reversal_reason,
            'version' => $this->version,
        ];
    }
}
