<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\InventoryItemResolver;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Setup\Models\Supplier;
use App\Support\BranchAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceService
{
    public function __construct(private readonly InventoryItemResolver $items) {}

    public function authorizeAccess(PurchaseInvoice $invoice, User $actor): void
    {
        BranchAccess::authorize($actor, $invoice->branch_id);
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        return BranchAccess::scope(PurchaseInvoice::query(), $actor)
            ->with(['supplier', 'lines.item'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where('invoice_number', 'like', "%{$search}%");
            })
            ->when($filters['delivery_status'] ?? null, fn (Builder $query, string $status) => $query->where('delivery_status', $status))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, string $supplierId) => $query->where('supplier_id', $supplierId))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $actor): PurchaseInvoice {
            BranchAccess::authorize($actor, (int) $data['branch_id']);
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $this->assertSupplierEligible($supplier);

            $invoice = PurchaseInvoice::query()->create([
                'invoice_number' => $data['invoice_number'] ?? $this->invoiceNumber(),
                'invoice_date' => $data['invoice_date'],
                'currency_code' => $data['currency_code'] ?? $this->defaultCurrency((int) $data['branch_id']),
                'supplier_id' => $supplier->id,
                'branch_id' => $data['branch_id'],
                'status' => PurchaseInvoice::STATUS_OPEN,
                'delivery_status' => PurchaseInvoice::DELIVERY_NOT_RECEIVED,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            $this->replaceLines($invoice, $data['lines'], $actor);
            $this->recalculate($invoice);

            return $invoice->refresh()->load(['supplier', 'lines.item']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseInvoice $invoice, array $data, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()->with('lines')->lockForUpdate()->findOrFail($invoice->id);
            $this->authorizeAccess($invoice, $actor);
            $this->assertEditable($invoice);

            if (isset($data['supplier_id'])) {
                $supplier = Supplier::query()->findOrFail($data['supplier_id']);
                $this->assertSupplierEligible($supplier);
            }

            BranchAccess::authorize($actor, (int) ($data['branch_id'] ?? $invoice->branch_id));
            $invoice->fill([
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'currency_code' => $data['currency_code'] ?? $invoice->currency_code,
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'notes' => $data['notes'] ?? $invoice->notes,
                'version' => $invoice->version + 1,
            ])->save();

            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $this->replaceLines($invoice, $data['lines'], $actor);
            }
            $this->recalculate($invoice);

            return $invoice->refresh()->load(['supplier', 'lines.item']);
        });
    }

    public function cancel(PurchaseInvoice $invoice, string $reason, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->authorizeAccess($invoice, $actor);
            $this->assertEditable($invoice, true);

            $invoice->fill([
                'status' => PurchaseInvoice::STATUS_CANCELLED,
                'delivery_status' => PurchaseInvoice::DELIVERY_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by_id' => $actor->id,
                'cancelled_at' => now(),
                'version' => $invoice->version + 1,
            ])->save();

            return $invoice->refresh()->load(['supplier', 'lines.item']);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(PurchaseInvoice $invoice, array $lines, User $actor): void
    {
        $supplier = $invoice->supplier ?: Supplier::query()->findOrFail($invoice->supplier_id);

        foreach (array_values($lines) as $index => $line) {
            BranchAccess::locations($actor, (int) $invoice->branch_id, $line['target_inventory_id'] ?? null, $line['target_farm_information_id'] ?? null);
            $category = strtolower($line['category']);
            $item = Item::query()->findOrFail($line['item_id']);
            if ($item->category !== $category) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'Item category does not match the purchase line category.']);
            }

            if (! in_array($category, ['food', 'medicine', 'equipment', 'animal'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.category" => 'Purchase category must be food, medicine, equipment, or animal.']);
            }

            $this->assertSupplierSupportsCategory($supplier, $item, $index);

            $quantity = round((float) $line['quantity'], 6);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Quantity must be greater than zero.']);
            }
            if (! $item->divisible_quantity && floor($quantity) != $quantity) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Whole-number quantity is required for this item.']);
            }

            $unitPrice = BigDecimal::of((string) $line['unit_price'])->toScale(2);
            $gross = BigDecimal::of((string) $line['quantity'])->toScale(6, RoundingMode::HalfUp)->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
            $value = BigDecimal::of((string) ($line['discount_value'] ?? 0));
            if (($line['discount_type'] ?? null) === 'percentage' && $value->isGreaterThan(100)) {
                throw ValidationException::withMessages(["lines.{$index}.discount_value" => 'Percentage discount cannot exceed 100.']);
            }
            $discount = match ($line['discount_type'] ?? null) {
                'percentage' => $gross->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HalfUp),
                'fixed' => $value->toScale(2, RoundingMode::HalfUp),
                default => BigDecimal::of('0.00'),
            };
            $taxBase = $gross->minus($discount);
            if ($taxBase->isNegative()) {
                throw ValidationException::withMessages(["lines.{$index}.discount_value" => 'Discount cannot make the taxable amount negative.']);
            }
            $taxRate = BigDecimal::of((string) ($line['tax_rate'] ?? 0));
            $tax = $taxBase->multipliedBy($taxRate)->dividedBy(100, 2, RoundingMode::HalfUp);
            $focQuantity = $this->focQuantity($quantity, $line['foc_type'] ?? null, (float) ($line['foc_value'] ?? 0), ! $item->divisible_quantity);

            $requiresInventory = in_array($category, ['food', 'medicine', 'equipment'], true);
            if ($requiresInventory && empty($line['target_inventory_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.target_inventory_id" => 'Target inventory is required for stock purchases.']);
            }
            if ($category === 'animal' && empty($line['target_farm_information_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.target_farm_information_id" => 'Target farm is required for animal purchases.']);
            }

            $invoice->lines()->create([
                'line_number' => $line['line_number'] ?? ($index + 1),
                'category' => $category,
                'item_id' => $item->id,
                'description' => $line['description'] ?? $item->name,
                'purchase_uom_id' => $line['purchase_uom_id'] ?? $item->purchase_uom_id,
                'stock_uom_id' => $line['stock_uom_id'] ?? $item->stock_uom_id,
                'conversion_factor' => $line['conversion_factor'] ?? $item->uom_conversion ?? 1,
                'quantity' => $quantity,
                'unit_price' => $this->money($unitPrice),
                'gross_amount' => $this->money($gross),
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? 0,
                'discount_amount' => $this->money($discount),
                'foc_type' => $line['foc_type'] ?? null,
                'foc_value' => $line['foc_value'] ?? 0,
                'foc_quantity' => $focQuantity,
                'tax_rate' => (string) $taxRate,
                'tax_amount' => $this->money($tax),
                'total_amount' => $this->money($taxBase->plus($tax)),
                'total_expected_quantity' => round($quantity + $focQuantity, 6),
                'target_inventory_id' => $line['target_inventory_id'] ?? null,
                'target_farm_information_id' => $line['target_farm_information_id'] ?? null,
                'target_location' => $line['target_location'] ?? 'MAIN',
                'metadata' => $line['metadata'] ?? null,
            ]);
        }
    }

    private function recalculate(PurchaseInvoice $invoice): void
    {
        $lines = $invoice->lines()->get();
        $invoice->fill([
            'gross_amount' => $this->money(BigDecimal::sum(...$lines->pluck('gross_amount')->all())),
            'discount_amount' => $this->money(BigDecimal::sum(...$lines->pluck('discount_amount')->all())),
            'tax_amount' => $this->money(BigDecimal::sum(...$lines->pluck('tax_amount')->all())),
            'total_amount' => $this->money(BigDecimal::sum(...$lines->pluck('total_amount')->all())),
            'total_expected_quantity' => $lines->sum(fn ($line) => (float) $line->total_expected_quantity),
        ])->save();
    }

    private function assertSupplierEligible(Supplier $supplier): void
    {
        if (($supplier->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier must be active.']);
        }
    }

    private function assertSupplierSupportsCategory(Supplier $supplier, Item $item, int $index): void
    {
        $categories = array_map('strtolower', $supplier->supplied_categories ?? []);
        $allowed = array_filter([$item->category, $item->master_category]);
        if ($categories !== [] && ! array_intersect($categories, $allowed)) {
            throw ValidationException::withMessages(["lines.{$index}.category" => 'Supplier is not eligible for this purchase category.']);
        }
    }

    private function assertEditable(PurchaseInvoice $invoice, bool $cancelling = false): void
    {
        if (! $cancelling && PurchasePayment::query()->where('purchase_invoice_id', $invoice->id)->whereNotNull('confirmed_at')->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Financial terms are immutable after a payment has been confirmed. Reverse the payments and cancel the invoice if it was entered incorrectly.']);
        }
        if (PurchasePayment::query()->where('purchase_invoice_id', $invoice->id)->whereIn('state', ['draft', 'confirmed'])->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Cancel draft payments and reverse confirmed payments before editing or cancelling the invoice.']);
        }
        if ($invoice->status === PurchaseInvoice::STATUS_CANCELLED || $invoice->delivery_status !== PurchaseInvoice::DELIVERY_NOT_RECEIVED) {
            throw ValidationException::withMessages(['invoice' => 'Only unreceived open purchases can be edited or cancelled.']);
        }
    }

    private function money(BigDecimal $amount): string
    {
        if ($amount->isNegative() || $amount->isGreaterThan('9999999999999999.99')) {
            throw ValidationException::withMessages(['amount' => 'Purchase amount exceeds the supported monetary range.']);
        }

        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }

    private function focQuantity(float $quantity, ?string $type, float $value, bool $whole): float
    {
        $foc = match ($type) {
            'percentage' => $quantity * ($value / 100),
            'quantity' => $value,
            default => 0,
        };

        return $whole ? floor($foc) : round($foc, 6);
    }

    private function invoiceNumber(): string
    {
        return 'PINV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }

    private function defaultCurrency(int $branchId): ?string
    {
        $configured = FinancialBranchSetting::query()->where('branch_id', $branchId)->value('default_currency');
        if ($configured) {
            return $configured;
        }

        $currencies = Cashbook::query()->where('branch_id', $branchId)->where('status', 'active')->distinct()->pluck('currency_code')->filter()->values();

        return $currencies->count() === 1 ? $currencies->first() : null;
    }
}
