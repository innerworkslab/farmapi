<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashLedgerCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'direction' => $this->direction,
            'reversal_category_id' => $this->reversal_category_id,
            'reversal_category' => $this->whenLoaded('reversalCategory', fn () => $this->reversalCategory ? [
                'id' => $this->reversalCategory->id,
                'name' => $this->reversalCategory->name,
                'direction' => $this->reversalCategory->direction,
                'status' => $this->reversalCategory->status,
            ] : null),
            'status' => $this->status,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
