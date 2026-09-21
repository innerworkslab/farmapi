<?php

namespace App\Modules\Farms\Resources;

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
            'farm_type' => $farm->currentAnimal?->type,
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
            'current_animal' => $farm->currentAnimal ? [
                'id' => $farm->currentAnimal->id,
                'code' => $farm->currentAnimal->code,
                'name' => $farm->currentAnimal->name,
                'tracking_type' => $farm->currentAnimal->tracking_type,
                'type' => $farm->currentAnimal->type,
                'category' => $farm->currentAnimal->category,
                'breed' => $farm->currentAnimal->breed,
            ] : null,
            'status' => 'active',
            'metrics' => [
                'total_animal_count' => $summary['total_animal_count'],
                'active_batch_count' => $summary['active_batch_count'],
                'individual_animal_count' => $summary['individual_animal_count'],
                'sick_animal_count' => 0,
                'death_count' => 0,
                'feed_consumption_summary' => [
                    'today_quantity' => 0,
                    'today_cost' => 0,
                    'records_today' => 0,
                ],
                'medicine_usage_summary' => [
                    'today_quantity' => 0,
                    'records_today' => 0,
                    'follow_ups_due' => 0,
                ],
            ],
            'animals' => FarmAnimalResource::collection($animals),
            'created_at' => $farm->created_at?->toISOString(),
            'updated_at' => $farm->updated_at?->toISOString(),
        ];
    }
}
