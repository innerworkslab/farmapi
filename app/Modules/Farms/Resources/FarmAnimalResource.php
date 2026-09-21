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
        $trackingType = $animal?->tracking_type;
        $isBatch = $trackingType === 'batch';
        $isIndividual = $trackingType === 'individual';

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'animal_id' => $animal?->id,
            'tracking_type' => $trackingType,
            'display_name' => $animal?->name ?? $item?->name,
            'name' => $animal?->name ?? $item?->name,
            'code' => $animal?->code ?? $item?->code,
            'batch_number' => $animal?->batch_flock_number,
            'rfid' => $animal?->ear_tag_rfid_number,
            'ear_tag' => $animal?->ear_tag_rfid_number,
            'batch' => [
                'batch_name' => $isBatch ? ($animal?->name ?? $item?->name) : null,
                'batch_number' => $isBatch ? $animal?->batch_flock_number : null,
                'initial_animal_count' => $isBatch ? (float) $this->on_hand_quantity : null,
                'current_animal_count' => $isBatch ? (float) $this->on_hand_quantity : null,
            ],
            'individual' => [
                'rfid' => $isIndividual ? $animal?->ear_tag_rfid_number : null,
                'ear_tag' => $isIndividual ? $animal?->ear_tag_rfid_number : null,
                'animal_name' => $isIndividual ? $animal?->name : null,
                'current_weight' => null,
            ],
            'animal_type' => $animal?->type,
            'category' => $animal?->category ?? $item?->master_category,
            'breed' => $animal?->breed,
            'gender' => $animal?->gender,
            'color_marking' => $animal?->color_marking,
            'age' => null,
            'life_stage' => null,
            'average_weight' => null,
            'current_quantity' => (float) $this->on_hand_quantity,
            'available_quantity' => (float) $this->available_quantity,
            'reserved_quantity' => (float) $this->reserved_quantity,
            'farm_information_id' => $this->farm_information_id,
            'inventory_id' => $this->inventory_id,
            'location' => [
                'name' => $this->location,
                'house_barn' => $this->farm?->house_barn,
                'pen_cage_pond' => $this->farm?->pen_cage_pond,
            ],
            'stock_lot_id' => $this->stock_lot_id,
            'health_status' => 'healthy',
            'animal_status' => ((float) $this->on_hand_quantity > 0) ? 'active' : 'inactive',
            'can_receive_operations' => (float) $this->on_hand_quantity > 0,
            'actions' => [
                'add_food' => [
                    'enabled' => (float) $this->on_hand_quantity > 0,
                    'method' => 'POST',
                    'href' => null,
                ],
                'add_medicine' => [
                    'enabled' => (float) $this->on_hand_quantity > 0,
                    'method' => 'POST',
                    'href' => null,
                ],
                'history_details' => [
                    'enabled' => true,
                    'method' => 'GET',
                    'href' => "/api/v1/farms/{$this->farm_information_id}/animals/{$this->id}",
                ],
                'add_defect_death' => [
                    'enabled' => (float) $this->on_hand_quantity > 0,
                    'method' => 'POST',
                    'href' => null,
                ],
            ],
            'history_summary' => [
                'feeding_records' => 0,
                'medication_records' => 0,
                'defect_records' => 0,
                'mortality_records' => 0,
                'transfer_records' => 0,
                'production_records' => 0,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}