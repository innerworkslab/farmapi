<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'type' => $this->type,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'branch_id' => $this->branch_id,
            'inventory_id' => $this->inventory_id,
            'farm_information_id' => $this->farm_information_id,
            'status' => $this->status,
            'reason_type' => $this->reason_type,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'posting_batch_id' => $this->posting_batch_id,
            'version' => $this->version,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'lines' => InventoryAdjustmentLineResource::collection($this->whenLoaded('lines')),
            'confirmation' => new InventoryConfirmationResource($this->whenLoaded('confirmation')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
