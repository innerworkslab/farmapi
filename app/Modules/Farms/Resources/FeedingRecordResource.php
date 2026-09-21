<?php

namespace App\Modules\Farms\Resources;

use App\Modules\Inventory\Resources\InventoryConfirmationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedingRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feeding_number' => $this->feeding_number,
            'feeding_date' => $this->feeding_date?->toDateString(),
            'feeding_time' => $this->feeding_time,
            'branch_id' => $this->branch_id,
            'farm_information_id' => $this->farm_information_id,
            'farm' => $this->farmInformation ? [
                'id' => $this->farmInformation->id,
                'name' => $this->farmInformation->name,
                'house_barn' => $this->farmInformation->house_barn,
                'pen_cage_pond' => $this->farmInformation->pen_cage_pond,
            ] : null,
            'target' => [
                'animal_balance_id' => $this->animal_balance_id,
                'animal_item_id' => $this->animal_item_id,
                'animal_id' => $this->animal_id,
                'target_type' => $this->target_type,
                'animal_type' => $this->animal_type,
                'breed' => $this->breed,
                'animal_count' => (float) $this->animal_count,
                'location' => $this->location,
            ],
            'status' => $this->status,
            'notes' => $this->notes,
            'totals' => [
                'quantity' => (float) $this->lines->sum(fn ($line) => (float) $line->quantity),
                'wastage_quantity' => (float) $this->lines->sum(fn ($line) => (float) $line->wastage_quantity),
            ],
            'posting_batch_id' => $this->posting_batch_id,
            'version' => $this->version,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'lines' => FeedingLineResource::collection($this->whenLoaded('lines')),
            'confirmation' => new InventoryConfirmationResource($this->whenLoaded('confirmation')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}