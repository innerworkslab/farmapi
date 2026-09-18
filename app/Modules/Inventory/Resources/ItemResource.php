<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'master_category' => $this->master_category,
            'itemable_type' => $this->itemable_type,
            'itemable_id' => $this->itemable_id,
            'stock_uom_id' => $this->stock_uom_id,
            'purchase_uom_id' => $this->purchase_uom_id,
            'usage_uom_id' => $this->usage_uom_id,
            'uom_conversion' => $this->uom_conversion,
            'batch_tracking' => $this->batch_tracking,
            'expiry_tracking' => $this->expiry_tracking,
            'serial_tracking' => $this->serial_tracking,
            'asset_tracking' => $this->asset_tracking,
            'cold_chain_required' => $this->cold_chain_required,
            'divisible_quantity' => $this->divisible_quantity,
            'minimum_stock_level' => $this->minimum_stock_level,
            'maximum_stock_level' => $this->maximum_stock_level,
            'reorder_level' => $this->reorder_level,
            'reorder_quantity' => $this->reorder_quantity,
            'status' => $this->status,
            'source_version' => $this->source_version,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
