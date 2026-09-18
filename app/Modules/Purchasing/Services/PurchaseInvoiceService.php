<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\InventoryItemResolver;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Setup\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceService
{
    public function __construct(private readonly InventoryItemResolver $items) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return PurchaseInvoice::query()
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
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $this->assertSupplierEligible($supplier);

            $invoice = PurchaseInvoice::query()->create([
                'invoice_number' => $data['invoice_number'] ?? $this->invoiceNumber(),
                'invoice_date' => $data['invoice_date'],
                'supplier_id' => $supplier->id,
                'branch_id' => $data['branch_id'],
                'status' => PurchaseInvoice::STATUS_OPEN,
                'delivery_status' => PurchaseInvoice::DELIVERY_NOT_RECEIVED,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            $this->replaceLines($invoice, $data['lines']);
            $this->recalculate($invoice);

            return $invoice->refresh()->load(['supplier', 'lines.item']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()->with('lines')->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);

            if (isset($data['supplier_id'])) {
                $supplier = Supplier::query()->findOrFail($data['supplier_id']);
                $this->assertSupplierEligible($supplier);
            }

            $invoice->fill([
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'notes' => $data['notes'] ?? $invoice->notes,
                'version' => $invoice->version + 1,
            ])->save();

            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $this->replaceLines($invoice, $data['lines']);
            }
            $this->recalculate($invoice);

            return $invoice->refresh()->load(['supplier', 'lines.item']);
        });
    }

    public function cancel(PurchaseInvoice $invoice, string $reason, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);

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
    private function replaceLines(PurchaseInvoice $invoice, array $lines): void
    {
        $supplier = $invoice->supplier ?: Supplier::query()->findOrFail($invoice->supplier_id);

        foreach (array_values($lines) as $index => $line) {
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

            $unitPrice = round((float) $line['unit_price'], 2);
            $gross = round($quantity * $unitPrice, 2);
            $discount = $this->discountAmount($gross, $line['discount_type'] ?? null, (float) ($line['discount_value'] ?? 0));
            $taxBase = round($gross - $discount, 2);
            if ($taxBase < 0) {
                throw ValidationException::withMessages(["lines.{$index}.discount_value" => 'Discount cannot make the taxable amount negative.']);
            }
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $tax = round($taxBase * ($taxRate / 100), 2);
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
                'unit_price' => $unitPrice,
                'gross_amount' => $gross,
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? 0,
                'discount_amount' => $discount,
                'foc_type' => $line['foc_type'] ?? null,
                'foc_value' => $line['foc_value'] ?? 0,
                'foc_quantity' => $focQuantity,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'total_amount' => round($taxBase + $tax, 2),
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
            'gross_amount' => $lines->sum(fn ($line) => (float) $line->gross_amount),
            'discount_amount' => $lines->sum(fn ($line) => (float) $line->discount_amount),
            'tax_amount' => $lines->sum(fn ($line) => (float) $line->tax_amount),
            'total_amount' => $lines->sum(fn ($line) => (float) $line->total_amount),
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

    private function assertEditable(PurchaseInvoice $invoice): void
    {
        if ($invoice->status === PurchaseInvoice::STATUS_CANCELLED || $invoice->delivery_status !== PurchaseInvoice::DELIVERY_NOT_RECEIVED) {
            throw ValidationException::withMessages(['invoice' => 'Only unreceived open purchases can be edited or cancelled.']);
        }
    }

    private function discountAmount(float $gross, ?string $type, float $value): float
    {
        return match ($type) {
            'percentage' => round($gross * ($value / 100), 2),
            'fixed' => round($value, 2),
            default => 0,
        };
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
}