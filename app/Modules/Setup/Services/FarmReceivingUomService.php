<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Models\Uom;
use Illuminate\Validation\ValidationException;

class FarmReceivingUomService
{
    public function defaultUnit(): Uom
    {
        $unit = Uom::withTrashed()->firstOrCreate(['code' => 'HEAD-FARM'], [
            'name' => 'Animal / equipment count', 'symbol' => 'unit', 'category' => 'unit', 'status' => 'active',
        ]);
        $this->assertCountUnit($unit);

        return $unit;
    }

    public function normalize(array $line): array
    {
        $id = $line['purchase_uom_id'] ?? $line['stock_uom_id'] ?? null;
        $unit = $id ? Uom::find($id) : $this->defaultUnit();
        $this->assertCountUnit($unit);
        if ((isset($line['purchase_uom_id']) && (int) $line['purchase_uom_id'] !== $unit->id)
            || (isset($line['stock_uom_id']) && (int) $line['stock_uom_id'] !== $unit->id)
            || (isset($line['conversion_factor']) && (float) $line['conversion_factor'] !== 1.0)) {
            throw ValidationException::withMessages(['stock_uom_id' => 'Animals and equipment use the same purchase/stock count unit with conversion 1.']);
        }

        return array_replace($line, ['purchase_uom_id' => $unit->id, 'stock_uom_id' => $unit->id, 'conversion_factor' => 1]);
    }

    private function assertCountUnit(?Uom $unit): void
    {
        if (! $unit || $unit->trashed() || $unit->status !== 'active' || ! in_array($unit->category, ['unit', 'quantity'], true)) {
            throw ValidationException::withMessages(['stock_uom_id' => 'Select an active animal/equipment count unit.']);
        }
    }
}
