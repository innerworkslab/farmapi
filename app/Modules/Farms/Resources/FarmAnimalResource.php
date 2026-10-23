<?php

namespace App\Modules\Farms\Resources;

use App\Modules\Setup\Resources\AnimalClassificationResource;
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
        $profile = $this->profile;
        $initial = (float) ($profile?->initial_quantity ?? $this->on_hand_quantity);
        $deaths = $profile?->death_quantity ?? 0;

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
                'initial_animal_count' => $isBatch ? $initial : null,
                'current_animal_count' => $isBatch ? (float) $this->on_hand_quantity : null,
            ],
            'individual' => [
                'rfid' => $isIndividual ? $animal?->ear_tag_rfid_number : null,
                'ear_tag' => $isIndividual ? $animal?->ear_tag_rfid_number : null,
                'animal_name' => $isIndividual ? $animal?->name : null,
                'current_weight' => $profile?->weight,
            ],
            'animal_type_id' => $animal?->animal_type_id,
            'animal_category_id' => $animal?->animal_category_id,
            'animal_breed_id' => $animal?->animal_breed_id,
            'animal_type' => $animal?->animalType ? new AnimalClassificationResource($animal->animalType) : null,
            'animal_category' => $animal?->animalCategory ? new AnimalClassificationResource($animal->animalCategory) : null,
            'animal_breed' => $animal?->animalBreed ? new AnimalClassificationResource($animal->animalBreed) : null,
            'gender' => $animal?->gender,
            'color_marking' => $animal?->color_marking,
            'age' => $profile?->date_of_birth ? (int) $profile->date_of_birth->diffInDays(now()) : null,
            'date_of_birth' => $profile?->date_of_birth?->toDateString(),
            'life_stage' => $profile?->life_stage,
            'average_weight' => $profile?->weight,
            'current_quantity' => (float) $this->on_hand_quantity,
            'available_quantity' => (float) $this->available_quantity,
            'reserved_quantity' => (float) $this->reserved_quantity,
            'farm_information_id' => $this->farm_information_id,
            'inventory_id' => $this->inventory_id,
            'location' => [
                'name' => $this->location,
                'house_barn_id' => $this->farmInformation?->house_barn_id,
                'house_barn' => $this->farmInformation?->houseBarn ? new \App\Modules\Setup\Resources\FarmLocationResource($this->farmInformation?->houseBarn) : null,
                'pen_cage_pond_id' => $this->farmInformation?->pen_cage_pond_id,
                'pen_cage_pond' => $this->farmInformation?->penCagePond ? new \App\Modules\Setup\Resources\FarmLocationResource($this->farmInformation?->penCagePond) : null,
            ],
            'stock_lot_id' => $this->stock_lot_id,
            'health_status' => $profile?->health_status ?? 'healthy',
            'death_quantity' => $deaths,
            'mortality_threshold_exceeded' => (int) $this->active_mortality_alerts > 0,
            'mortality_rate' => $initial > 0 ? round($deaths / $initial * 100, 4) : 0,
            'affected_quantity' => $profile?->affected_quantity ?? 0,
            'withdrawal_end_date' => $profile?->withdrawal_end_date?->toDateString(),
            'animal_status' => $profile?->animal_state === 'dead' ? 'dead' : (((float) $this->on_hand_quantity > 0) ? 'active' : 'inactive'),
            'can_receive_operations' => (float) $this->on_hand_quantity > 0 && ($this->farmInformation?->setting?->status ?? 'active') === 'active',
            'actions' => [
                'add_food' => [
                    'enabled' => (float) $this->on_hand_quantity > 0 && ($this->farmInformation?->setting?->status ?? 'active') === 'active',
                    'method' => 'POST',
                    'href' => '/api/v1/farms/feedings',
                ],
                'add_medicine' => [
                    'enabled' => (float) $this->on_hand_quantity > 0 && ($this->farmInformation?->setting?->status ?? 'active') === 'active',
                    'method' => 'POST',
                    'href' => '/api/v1/farms/operations',
                ],
                'history_details' => [
                    'enabled' => true,
                    'method' => 'GET',
                    'href' => "/api/v1/farms/{$this->farm_information_id}/animals/{$this->id}",
                ],
                'add_defect_death' => [
                    'enabled' => (float) $this->on_hand_quantity > 0 && ($this->farmInformation?->setting?->status ?? 'active') === 'active',
                    'method' => 'POST',
                    'href' => '/api/v1/farms/operations',
                ],
            ],
            'timeline_url' => "/api/v1/farms/{$this->farm_information_id}/timeline?animal_balance_id={$this->id}",
            'history_summary' => [
                'feeding_records' => (int) $this->feedings_count + (int) $this->feeding_targets_count,
                'medication_records' => (int) $this->medication_records,
                'defect_records' => (int) $this->defect_records,
                'mortality_records' => (int) $this->mortality_records,
                'transfer_records' => (int) $this->transfer_records,
                'production_records' => (int) $this->production_records,
                'purchase_records' => (int) $this->purchase_records,
                'sale_records' => (int) $this->sale_records,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
