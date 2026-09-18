<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptService
{
    public function __construct(private readonly InventoryPostingService $posting) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return PurchaseReceipt::query()
            ->with(['invoice.supplier', 'lines.item'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['purchase_invoice_id'] ?? null, fn (Builder $query, string $id) => $query->where('purchase_invoice_id', $id))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PurchaseReceipt
    {
        return DB::transaction(function () use ($data, $actor): PurchaseReceipt {
            $invoice = PurchaseInvoice::query()->with('lines')->lockForUpdate()->findOrFail($data['purchase_invoice_id']);
            $this->assertReceivable($invoice);

            $receipt = PurchaseReceipt::query()->create([
                'receipt_number' => $data['receipt_number'] ?? $this->receiptNumber(),
                'purchase_invoice_id' => $invoice->id,
                'receipt_date' => $data['receipt_date'],
                'status' => PurchaseReceipt::STATUS_DRAFT,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            $this->replaceLines($receipt, $invoice, $data['lines']);

            return $receipt->refresh()->load(['invoice.supplier', 'lines.item']);
        });
    }

    public function confirm(PurchaseReceipt $receipt, User $actor): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $actor): PurchaseReceipt {
            $receipt = PurchaseReceipt::query()->with(['invoice.lines', 'lines'])->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
                throw ValidationException::withMessages(['receipt' => 'Only draft receipts can be confirmed.']);
            }

            $invoice = PurchaseInvoice::query()->with('lines')->lockForUpdate()->findOrFail($receipt->purchase_invoice_id);
            $this->assertReceivable($invoice);
            $this->validateReceiptLinesAgainstRemaining($receipt, $invoice);

            $postingBatchId = 'PRB-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
            foreach ($receipt->lines as $line) {
                if (! in_array($line->category, ['food', 'medicine'], true)) {
                    throw ValidationException::withMessages(['category' => 'Only food and medicine receipts can post to stock in this implementation.']);
                }

                $totalReceived = round((float) $line->accepted_quantity + (float) $line->accepted_foc_quantity, 6);
                if ($totalReceived <= 0) {
                    continue;
                }

                $lotStatus = $line->category === 'medicine' && in_array($line->cold_chain_status, ['breached', 'unknown'], true)
                    ? 'quarantined'
                    : 'available';

                $this->posting->postInboundStock([
                    'category' => $line->category,
                    'item_id' => $line->item_id,
                    'branch_id' => $invoice->branch_id,
                    'inventory_id' => $line->target_inventory_id,
                    'farm_information_id' => $line->target_farm_information_id,
                    'location' => $line->target_location ?: 'MAIN',
                    'stock_uom_id' => $line->stock_uom_id,
                    'supplier_id' => $invoice->supplier_id,
                    'supplier_batch_number' => $line->supplier_batch_number,
                    'receipt_lot_number' => $line->receipt_lot_number,
                    'manufacturing_date' => $line->manufacturing_date?->toDateString(),
                    'expiry_date' => $line->expiry_date?->toDateString(),
                    'lot_status' => $lotStatus,
                    'cold_chain_required' => $line->cold_chain_required,
                    'cold_chain_status' => $line->cold_chain_status,
                    'observed_temperature' => $line->observed_temperature,
                    'temperature_uom' => $line->temperature_uom,
                    'restriction_reason' => $lotStatus === 'quarantined' ? ($line->exception_reason ?: 'Cold-chain status requires quarantine.') : null,
                    'source_type' => 'purchase_receipt',
                    'source_id' => $receipt->id,
                    'source_line_id' => $line->id,
                    'transaction_type' => 'purchase_receipt',
                    'original_quantity' => $totalReceived,
                    'original_uom_id' => $line->purchase_uom_id ?: $line->stock_uom_id,
                    'conversion_factor' => $line->conversion_factor,
                    'stock_quantity' => round($totalReceived * (float) $line->conversion_factor, 6),
                    'metadata' => [
                        'receipt_number' => $receipt->receipt_number,
                        'invoice_number' => $invoice->invoice_number,
                        'accepted_quantity' => $line->accepted_quantity,
                        'accepted_foc_quantity' => $line->accepted_foc_quantity,
                    ],
                ], $actor, $postingBatchId);
            }

            foreach ($receipt->lines as $line) {
                $invoiceLine = PurchaseInvoiceLine::query()->lockForUpdate()->findOrFail($line->purchase_invoice_line_id);
                $invoiceLine->received_quantity = round((float) $invoiceLine->received_quantity + (float) $line->accepted_quantity, 6);
                $invoiceLine->received_foc_quantity = round((float) $invoiceLine->received_foc_quantity + (float) $line->accepted_foc_quantity, 6);
                $invoiceLine->save();
            }

            $this->refreshInvoiceDelivery($invoice);
            $receipt->fill([
                'status' => PurchaseReceipt::STATUS_CONFIRMED,
                'posting_batch_id' => $postingBatchId,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'version' => $receipt->version + 1,
            ])->save();

            return $receipt->refresh()->load(['invoice.supplier', 'lines.item']);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(PurchaseReceipt $receipt, PurchaseInvoice $invoice, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $invoiceLine = $invoice->lines->firstWhere('id', (int) $line['purchase_invoice_line_id']);
            if (! $invoiceLine) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_invoice_line_id" => 'Receipt line must reference this invoice.']);
            }

            $accepted = round((float) ($line['accepted_quantity'] ?? 0), 6);
            $acceptedFoc = round((float) ($line['accepted_foc_quantity'] ?? 0), 6);
            $rejected = round((float) ($line['rejected_quantity'] ?? 0), 6);
            if ($accepted < 0 || $acceptedFoc < 0 || $rejected < 0) {
                throw ValidationException::withMessages(["lines.{$index}.accepted_quantity" => 'Receipt quantities cannot be negative.']);
            }

            $receipt->lines()->create([
                'purchase_invoice_line_id' => $invoiceLine->id,
                'line_number' => $line['line_number'] ?? ($index + 1),
                'category' => $invoiceLine->category,
                'item_id' => $invoiceLine->item_id,
                'accepted_quantity' => $accepted,
                'accepted_foc_quantity' => $acceptedFoc,
                'rejected_quantity' => $rejected,
                'rejection_reason' => $line['rejection_reason'] ?? null,
                'target_inventory_id' => $line['target_inventory_id'] ?? $invoiceLine->target_inventory_id,
                'target_farm_information_id' => $line['target_farm_information_id'] ?? $invoiceLine->target_farm_information_id,
                'target_location' => $line['target_location'] ?? $invoiceLine->target_location ?? 'MAIN',
                'purchase_uom_id' => $invoiceLine->purchase_uom_id,
                'stock_uom_id' => $invoiceLine->stock_uom_id,
                'conversion_factor' => $invoiceLine->conversion_factor,
                'supplier_batch_number' => $line['supplier_batch_number'] ?? null,
                'receipt_lot_number' => $line['receipt_lot_number'] ?? null,
                'manufacturing_date' => $line['manufacturing_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'cold_chain_required' => $line['cold_chain_required'] ?? false,
                'cold_chain_status' => $line['cold_chain_status'] ?? null,
                'observed_temperature' => $line['observed_temperature'] ?? null,
                'temperature_uom' => $line['temperature_uom'] ?? null,
                'exception_reason' => $line['exception_reason'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ]);
        }
    }

    private function validateReceiptLinesAgainstRemaining(PurchaseReceipt $receipt, PurchaseInvoice $invoice): void
    {
        foreach ($receipt->lines as $line) {
            $invoiceLine = $invoice->lines->firstWhere('id', $line->purchase_invoice_line_id);
            $remainingPaid = round((float) $invoiceLine->quantity - (float) $invoiceLine->received_quantity, 6);
            $remainingFoc = round((float) $invoiceLine->foc_quantity - (float) $invoiceLine->received_foc_quantity, 6);
            if ((float) $line->accepted_quantity > $remainingPaid || (float) $line->accepted_foc_quantity > $remainingFoc) {
                throw ValidationException::withMessages(['lines' => 'Receipt quantities cannot exceed remaining purchase quantities.']);
            }
            if (in_array($line->category, ['food', 'medicine'], true) && ! $line->target_inventory_id) {
                throw ValidationException::withMessages(['target_inventory_id' => 'Target inventory is required for stock receipts.']);
            }
            if (in_array($line->category, ['food', 'medicine'], true) && ! $line->stock_uom_id) {
                throw ValidationException::withMessages(['stock_uom_id' => 'Stock UOM is required for stock receipts.']);
            }
            if (in_array($line->category, ['food', 'medicine'], true) && ! $line->receipt_lot_number) {
                throw ValidationException::withMessages(['receipt_lot_number' => 'Receipt lot number is required for food and medicine receipts.']);
            }
            if ($line->category === 'medicine' && ! in_array($line->cold_chain_status, ['compliant', 'breached', 'unknown'], true)) {
                throw ValidationException::withMessages(['cold_chain_status' => 'Medicine receipts require cold-chain status.']);
            }
        }
    }

    private function refreshInvoiceDelivery(PurchaseInvoice $invoice): void
    {
        $invoice->refresh()->load('lines');
        $expected = round((float) $invoice->lines->sum(fn ($line) => (float) $line->total_expected_quantity), 6);
        $received = round((float) $invoice->lines->sum(fn ($line) => (float) $line->received_quantity + (float) $line->received_foc_quantity), 6);
        $status = $received <= 0 ? PurchaseInvoice::DELIVERY_NOT_RECEIVED : ($received >= $expected ? PurchaseInvoice::DELIVERY_FULLY_RECEIVED : PurchaseInvoice::DELIVERY_PARTIALLY_RECEIVED);

        $invoice->fill([
            'total_expected_quantity' => $expected,
            'total_received_quantity' => $received,
            'delivery_status' => $status,
            'version' => $invoice->version + 1,
        ])->save();
    }

    private function assertReceivable(PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== PurchaseInvoice::STATUS_OPEN || $invoice->delivery_status === PurchaseInvoice::DELIVERY_CANCELLED || $invoice->delivery_status === PurchaseInvoice::DELIVERY_FULLY_RECEIVED) {
            throw ValidationException::withMessages(['invoice' => 'Purchase invoice is not eligible for receiving.']);
        }
    }

    private function receiptNumber(): string
    {
        return 'PREC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }
}