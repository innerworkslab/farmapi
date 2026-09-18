<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryConfirmationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'confirmation_number' => $this->confirmation_number,
            'source_module' => $this->source_module,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'submission_version' => $this->submission_version,
            'status' => $this->status,
            'submitted_by_id' => $this->submitted_by_id,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'confirmed_by_id' => $this->confirmed_by_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'rejected_by_id' => $this->rejected_by_id,
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'posting_batch_id' => $this->posting_batch_id,
            'validation_snapshot' => $this->validation_snapshot,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
