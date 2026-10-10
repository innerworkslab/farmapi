<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = array_merge(['id'], $this->resource->getFillable(), ['created_at', 'updated_at']);

        return array_intersect_key($this->resource->attributesToArray(), array_flip($fields));
    }
}
