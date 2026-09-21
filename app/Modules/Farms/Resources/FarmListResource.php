<?php

namespace App\Modules\Farms\Resources;

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
            'farm_type' => $this->currentAnimal?->type,
            'animal_type' => $this->currentAnimal?->type,
            'branch' => [
                'id' => $this->branch?->id,
                'code' => $this->branch?->code,
                'name' => $this->branch?->name,
                'status' => $this->branch?->status,
            ],
            'house_barn' => $this->house_barn,
            'pen_cage_pond' => $this->pen_cage_pond,
            'animal_count' => (float) ($this->animal_count ?? 0),
            'active_batch_count' => (int) ($this->active_batch_count ?? 0),
            'individual_animal_count' => (float) ($this->individual_animal_count ?? 0),
            'farm_manager' => $this->responsibleEmployee ? [
                'id' => $this->responsibleEmployee->id,
                'name' => $this->responsibleEmployee->name,
                'email' => $this->responsibleEmployee->email,
            ] : null,
            'status' => 'active',
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
