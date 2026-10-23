<?php

namespace App\Modules\Farms\Resources;

use App\Modules\Setup\Resources\AnimalClassificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_code' => sprintf('FRM-%05d', $this->id),
            'name' => $this->name,
            'animal_type_id' => $this->currentAnimal?->animal_type_id,
            'animal_type' => $this->currentAnimal?->animalType ? new AnimalClassificationResource($this->currentAnimal->animalType) : null,
            'branch' => [
                'id' => $this->branch?->id,
                'code' => $this->branch?->code,
                'name' => $this->branch?->name,
                'status' => $this->branch?->status,
            ],
            'house_barn_id' => $this->house_barn_id,
            'house_barn' => $this->houseBarn ? new \App\Modules\Setup\Resources\FarmLocationResource($this->houseBarn) : null,
            'pen_cage_pond_id' => $this->pen_cage_pond_id,
            'pen_cage_pond' => $this->penCagePond ? new \App\Modules\Setup\Resources\FarmLocationResource($this->penCagePond) : null,
            'animal_count' => (float) ($this->animal_count ?? 0),
            'active_batch_count' => (int) ($this->active_batch_count ?? 0),
            'individual_animal_count' => (float) ($this->individual_animal_count ?? 0),
            'farm_manager' => $this->responsibleEmployee ? [
                'id' => $this->responsibleEmployee->id,
                'name' => $this->responsibleEmployee->name,
                'email' => $this->responsibleEmployee->email,
            ] : null,
            'status' => $this->setting?->status ?? 'active',
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
