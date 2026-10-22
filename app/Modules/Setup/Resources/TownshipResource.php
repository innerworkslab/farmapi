<?php

namespace App\Modules\Setup\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TownshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'division_id' => $this->resource['division_id'],
            'name' => $this->resource['name'],
        ];
    }
}
