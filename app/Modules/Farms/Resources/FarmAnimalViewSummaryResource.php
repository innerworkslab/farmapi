<?php

namespace App\Modules\Farms\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmAnimalViewSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $farm = $this['farm'];

        return [
            'farm' => [
                'id' => $farm->id,
                'farm_code' => sprintf('FRM-%05d', $farm->id),
                'name' => $farm->name,
                'branch' => [
                    'id' => $farm->branch?->id,
                    'code' => $farm->branch?->code,
                    'name' => $farm->branch?->name,
                ],
                'house_barn' => $farm->house_barn,
                'pen_cage_pond' => $farm->pen_cage_pond,
            ],
            'tabs' => $this['tabs'],
            'filter_options' => $this['filter_options'],
            'selected_filters' => $this['selected_filters'],
            'actions' => [
                'views' => [
                    ['key' => 'all', 'label' => 'All'],
                    ['key' => 'batch', 'label' => 'Batch'],
                    ['key' => 'individual', 'label' => 'Individual'],
                ],
                'row_actions' => ['add_food', 'add_medicine', 'history_details', 'add_defect_death'],
            ],
        ];
    }
}