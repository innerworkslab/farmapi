<?php

namespace App\Modules\Setup\Resources;

use App\Modules\Setup\Models\PenCagePond;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'branch_id' => $this->branch_id, 'name' => $this->name,
            'house_barn_id' => $this->when($this->resource instanceof PenCagePond, fn () => $this->house_barn_id),
            'status' => $this->status, 'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
