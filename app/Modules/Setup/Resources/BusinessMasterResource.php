<?php

namespace App\Modules\Setup\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessMasterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        foreach (['created_at', 'updated_at', 'deleted_at'] as $field) {
            if (isset($this->{$field})) {
                $data[$field] = $this->{$field}?->toISOString();
            }
        }

        return $data;
    }
}