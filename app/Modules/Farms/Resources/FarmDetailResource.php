<?php

namespace App\Modules\Farms\Resources;

use App\Modules\Setup\Resources\AnimalClassificationResource;
use App\Modules\Setup\Resources\AnimalResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $farm = $this['farm'];
        $summary = $this['summary'];
        $animals = $this['animals'];

        return [
            'id' => $farm->id,
            'farm_code' => sprintf('FRM-%05d', $farm->id),
            'name' => $farm->name,
            'animal_type_id' => $farm->currentAnimal?->animal_type_id,
            'animal_type' => $farm->currentAnimal?->animalType ? new AnimalClassificationResource($farm->currentAnimal->animalType) : null,
            'branch' => [
                'id' => $farm->branch?->id,
                'code' => $farm->branch?->code,
                'name' => $farm->branch?->name,
                'status' => $farm->branch?->status,
            ],
            'farm_manager' => $farm->responsibleEmployee ? [
                'id' => $farm->responsibleEmployee->id,
                'name' => $farm->responsibleEmployee->name,
                'email' => $farm->responsibleEmployee->email,
            ] : null,
            'house_barn' => $farm->house_barn,
            'pen_cage_pond' => $farm->pen_cage_pond,
            'current_animal' => $farm->currentAnimal ? new AnimalResource($farm->currentAnimal) : null,
            'status' => $farm->setting?->status ?? 'active',
            'metrics' => $summary,
            'animals' => FarmAnimalResource::collection($animals),
            'created_at' => $farm->created_at?->toISOString(),
            'updated_at' => $farm->updated_at?->toISOString(),
        ];
    }
}
