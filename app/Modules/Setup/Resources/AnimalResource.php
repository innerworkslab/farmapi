<?php

namespace App\Modules\Setup\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnimalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name,
            'tracking_type' => $this->tracking_type,
            'ear_tag_rfid_number' => $this->ear_tag_rfid_number,
            'batch_flock_number' => $this->batch_flock_number,
            'animal_type_id' => $this->animal_type_id,
            'animal_category_id' => $this->animal_category_id,
            'animal_breed_id' => $this->animal_breed_id,
            'animal_type' => new AnimalClassificationResource($this->whenLoaded('animalType')),
            'animal_category' => new AnimalClassificationResource($this->whenLoaded('animalCategory')),
            'animal_breed' => new AnimalClassificationResource($this->whenLoaded('animalBreed')),
            'gender' => $this->gender, 'color_marking' => $this->color_marking, 'photo_path' => $this->photo_path,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
