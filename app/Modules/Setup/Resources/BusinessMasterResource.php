<?php

namespace App\Modules\Setup\Resources;

use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessMasterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->resource instanceof Supplier) {
            $data['types'] = $this->whenLoaded('types', fn () => $this->types->pluck('type')->values()->all(), []);
        }
        if ($this->resource instanceof Customer) {
            unset($data['payment_terms']);
        }
        if ($this->resource instanceof Medicine) {
            $data['animal_type'] = new AnimalClassificationResource($this->whenLoaded('animalType'));
        }

        if ($this->resource instanceof Food || $this->resource instanceof Medicine) {
            $base = $this->whenLoaded('stockUom');
            $conversion = $this->whenLoaded('purchaseUom');
            $usage = $this->whenLoaded($this->resource instanceof Food ? 'consumptionUom' : 'usageUom');
            $unit = fn ($uom) => $uom instanceof Uom ? ['id' => $uom->id, 'code' => $uom->code, 'name' => $uom->name] : null;
            $data['unit_roles'] = [
                'purchase_unit' => $unit($conversion),
                'usage_unit' => $unit($usage),
                'stock_unit' => $unit($base),
                'purchase_to_usage_factor' => $this->uom_conversion,
                'purchase_to_stock_factor' => (int) $this->stock_uom_id === (int) $this->purchase_uom_id ? '1' : $this->uom_conversion,
                'base_unit' => $base instanceof Uom ? ['id' => $base->id, 'code' => $base->code, 'name' => $base->name] : null,
                'conversion_unit' => $conversion instanceof Uom ? ['id' => $conversion->id, 'code' => $conversion->code, 'name' => $conversion->name] : null,
                'conversion_factor' => (int) $this->stock_uom_id === (int) $this->purchase_uom_id ? '1' : $this->uom_conversion,
                'conversion_direction' => 'conversion_unit_to_base_unit',
            ];
        }

        foreach (['created_at', 'updated_at', 'deleted_at'] as $field) {
            if (isset($this->{$field})) {
                $data[$field] = $this->{$field}?->toISOString();
            }
        }

        if (isset($this->opening_balance_date)) {
            $data['opening_balance_date'] = $this->opening_balance_date?->toDateString();
        }

        return $data;
    }
}
