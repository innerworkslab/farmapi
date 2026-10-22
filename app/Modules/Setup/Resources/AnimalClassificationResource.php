<?php

namespace App\Modules\Setup\Resources;

use App\Modules\Setup\Models\AnimalType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnimalClassificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'animal_type_id' => $this->when(! ($this->resource instanceof AnimalType), fn () => $this->animal_type_id),
            'animal_type' => $this->whenLoaded('animalType', fn () => $this->animalType ? ['id' => $this->animalType->id, 'name' => $this->animalType->name, 'status' => $this->animalType->status] : null),
            'status' => $this->status,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
