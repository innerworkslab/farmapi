<?php

namespace App\Modules\Farms\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmAnimalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->item;
        $animal = $item?->itemable;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'animal_id' => $animal?->id,
            'tracking_type' => $animal?->tracking_type,
            'code' => $animal?->code ?? $item?->code,
            'name' => $animal?->name ?? $item?->name,
            'batch_number' => $animal?->batch_flock_number,
            'rfid' => $animal?->ear_tag_rfid_number,
            'ear_tag' => $animal?->ear_tag_rfid_number,
            'animal_type' => $animal?->type,
            'category' => $animal?->category ?? $item?->master_category,
            'breed' => $animal?->breed,
            'gender' => $animal?->gender,
            'color_marking' => $animal?->color_marking,
            'current_quantity' => (float) $this->on_hand_quantity,
            'available_quantity' => (float) $this->available_quantity,
            'reserved_quantity' => (float) $this->reserved_quantity,
            'health_status' => 'healthy',
            'animal_status' => ((float) $this->on_hand_quantity > 0) ? 'active' : 'inactive',
            'farm_information_id' => $this->farm_information_id,
            'inventory_id' => $this->inventory_id,
            'location' => $this->location,
            'stock_lot_id' => $this->stock_lot_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
