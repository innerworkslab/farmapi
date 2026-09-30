<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAdvanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $staff = $this->resource->staff;
        $branch = $this->resource->branch;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'staff_id' => $this->staff_id,
            'staff' => [
                'id' => $this->staff_id,
                'staff_code' => $this->staff_code_snapshot ?? $staff?->staff_code,
                'name' => $this->staff_name_snapshot ?? $staff?->name,
                'branch_id' => $this->branch_id,
                'branch_code' => $this->branch_code_snapshot ?? $branch?->code,
                'branch_name' => $this->branch_name_snapshot ?? $branch?->name,
            ],
            'principal_amount' => $this->principal_amount,
            'outstanding_amount' => $this->resource->getAttribute('outstanding_amount'),
            'business_date' => $this->business_date?->toDateString(),
            'cashbook_id' => $this->cashbook_id,
            'cashbook' => $this->whenLoaded('cashbook', fn () => $this->cashbook ? [
                'id' => $this->cashbook->id,
                'name' => $this->cashbook->name,
                'type' => $this->cashbook->type,
                'currency_code' => $this->currency_code,
            ] : null),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'direction' => $this->category->direction,
            ] : null),
            'description' => $this->description,
            'status' => $this->status,
            'cashbook_transaction_id' => $this->cashbook_transaction_id,
            'cashbook_posting_reference' => $this->whenLoaded('cashbookTransaction', fn () => $this->cashbookTransaction?->reference),
            'confirmed_by_id' => $this->confirmed_by_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'reversed_by_id' => $this->reversed_by_id,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'reversal_reason' => $this->reversal_reason,
            'version' => $this->version,
            'repayments' => StaffAdvanceRepaymentResource::collection($this->whenLoaded('repayments')),
        ];
    }
}
