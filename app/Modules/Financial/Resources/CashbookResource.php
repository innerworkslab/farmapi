<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashbookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'branch_id' => $this->branch_id,
            'type' => $this->type,
            'name' => $this->name,
            'currency_code' => $this->currency_code,
            'bank_reference' => $this->when($request->user()?->can('financial.cashbooks.bank-reference.view'), $this->bank_reference),
            'opening_balance' => $this->opening_balance,
            'effective_date' => $this->effective_date?->toDateString(),
            'current_balance' => $this->current_balance,
            'status' => $this->status,
            'deactivation_reason' => $this->deactivation_reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
