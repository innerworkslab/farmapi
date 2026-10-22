<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Repositories\ItemUomRepository;
use App\Modules\Setup\Models\Food;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemUomService
{
    public function __construct(private readonly ItemUomRepository $references) {}

    public function validateMaster(array $data, string $usageField, ?Model $master = null): void
    {
        $data = array_replace($master?->getAttributes() ?? [], $data);
        $this->validateDefinition($data, $usageField);
        $activeUom = fn () => Rule::exists('uoms', 'id')->where('status', 'active')->whereNull('deleted_at');
        Validator::make($data, [
            'purchase_uom_id' => [$activeUom()],
            $usageField => [$activeUom()],
            'stock_uom_id' => [$activeUom()],
        ])->validate();

        if (! $master) {
            return;
        }
        $item = $this->references->lockForMaster($master);
        $changed = false;
        foreach (['purchase_uom_id', $usageField, 'stock_uom_id', 'uom_conversion'] as $field) {
            $changed = $changed || ! BigDecimal::of((string) ($master->{$field} ?? 0))->isEqualTo((string) $data[$field]);
        }
        if ($changed && $item && $this->references->hasReferences($item)) {
            throw ValidationException::withMessages(['uom_conversion' => 'Unit definitions are immutable once the item has stock, plans or transactions. Create a new item for a different unit definition.']);
        }
    }

    public function purchaseToStock(Model $item): string
    {
        $this->validateDefinition($item->getAttributes(), $this->usageField($item));

        return (int) $item->stock_uom_id === (int) $item->purchase_uom_id ? '1' : (string) $item->uom_conversion;
    }

    public function toStock(Model $item, string|int|float $quantity, int $fromUomId): string
    {
        $this->purchaseToStock($item);
        $amount = BigDecimal::of((string) $quantity);
        if ($fromUomId === (int) $item->stock_uom_id) {
            return (string) $amount->toScale(6, RoundingMode::HalfUp);
        }
        if ($fromUomId === (int) $item->purchase_uom_id) {
            return (string) $amount->multipliedBy((string) $item->uom_conversion)->toScale(6, RoundingMode::HalfUp);
        }
        if ($fromUomId === (int) $item->{$this->usageField($item)}) {
            return (string) $amount->dividedBy((string) $item->uom_conversion, 6, RoundingMode::HalfUp);
        }

        throw ValidationException::withMessages(['quantity_uom_id' => 'Use the configured purchase or usage UOM.']);
    }

    public function consumptionCost(Model $master, string|int|float $stockQuantity): string
    {
        $factor = $this->purchaseToStock($master);
        $quantity = BigDecimal::of((string) $stockQuantity);
        if (BigDecimal::of((string) ($master->standard_cost ?? 0))->isGreaterThan(0)) {
            return (string) $quantity->multipliedBy((string) $master->standard_cost)->toScale(2, RoundingMode::HalfUp);
        }

        return (string) $quantity->multipliedBy((string) ($master->purchase_price ?? 0))
            ->dividedBy($factor, 2, RoundingMode::HalfUp);
    }

    private function usageField(Model $item): string
    {
        return $item instanceof Food ? 'consumption_uom_id' : 'usage_uom_id';
    }

    private function validateDefinition(array $data, string $usageField): void
    {
        Validator::make($data, [
            'purchase_uom_id' => ['required', 'integer', 'min:1'],
            $usageField => ['required', 'integer', 'min:1'],
            'stock_uom_id' => ['required', 'integer', Rule::in([$data['purchase_uom_id'] ?? null, $data[$usageField] ?? null])],
            'uom_conversion' => ['required', 'numeric', 'gt:0', 'lt:1000000000', 'decimal:0,6'],
        ], ['stock_uom_id.in' => 'Stock UOM must equal the purchase or usage UOM.'])->validate();
        if ((int) $data['purchase_uom_id'] === (int) $data[$usageField] && ! BigDecimal::of((string) $data['uom_conversion'])->isEqualTo(1)) {
            throw ValidationException::withMessages(['uom_conversion' => 'Conversion must be 1 when purchase and usage UOM are identical.']);
        }
    }
}
