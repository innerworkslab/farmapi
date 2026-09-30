<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAdvanceBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'staff_id' => $this->id,
            'staff_code' => $this->staff_code,
            'staff_name' => $this->name,
            'currency_code' => $this->resource->getAttribute('currency_code'),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ] : null),
            'employment_status' => $this->employment_status,
            'status' => $this->status,
            'total_additions' => $this->money($this->resource->getAttribute('total_additions')),
            'total_deductions' => $this->money($this->resource->getAttribute('total_deductions')),
            'outstanding_balance' => $this->money($this->resource->getAttribute('outstanding_balance')),
        ];
    }

    private function money(mixed $value): string
    {
        $parts = explode('.', (string) ($value ?? '0'));

        return ($parts[0] ?: '0').'.'.str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }
}
