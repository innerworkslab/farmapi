<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\EquipmentInstance;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryAdjustmentLine;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryPostingService
{
    public function __construct(
        private readonly InventoryItemResolver $items,
        private readonly StockIdentityService $identities,
    ) {}


    /** @param array<string, mixed> $movement */
    public function postInboundStock(array $movement, User $actor, ?string $postingBatchId = null): string
    {
        $postingBatchId ??= $this->postingBatchId();
        $resolved = $this->items->resolve($movement['category'], (int) $movement['item_id']);

        if (! in_array($movement['category'], ['food', 'medicine'], true)) {
            throw ValidationException::withMessages([
                'category' => 'Only food and medicine purchase receipts post to inventory stock in this implementation.',
            ]);
        }

        if ($resolved['batch_tracking'] && empty($movement['receipt_lot_number'])) {
            throw ValidationException::withMessages(['receipt_lot_number' => 'Receipt lot number is required.']);
        }

        if ($resolved['expiry_tracking'] && empty($movement['expiry_date'])) {
            throw ValidationException::withMessages(['expiry_date' => 'Expiry date is required.']);
        }

        $stockQuantity = round((float) $movement['stock_quantity'], 6);
        if ($stockQuantity <= 0) {
            throw ValidationException::withMessages(['stock_quantity' => 'Received stock quantity must be greater than zero.']);
        }

        $lotStatus = $movement['lot_status'] ?? 'available';
        $stockLot = StockLot::query()->create([
            'category' => $movement['category'],
            'item_type' => $resolved['item_type'],
            'item_id' => $movement['item_id'],
            'branch_id' => $movement['branch_id'],
            'inventory_id' => $movement['inventory_id'],
            'farm_information_id' => $movement['farm_information_id'] ?? null,
            'location' => $movement['location'] ?? 'MAIN',
            'stock_uom_id' => $movement['stock_uom_id'],
            'supplier_id' => $movement['supplier_id'] ?? null,
            'supplier_batch_number' => $movement['supplier_batch_number'] ?? null,
            'receipt_lot_number' => $movement['receipt_lot_number'] ?? null,
            'manufacturing_date' => $movement['manufacturing_date'] ?? null,
            'expiry_date' => $movement['expiry_date'] ?? null,
            'lot_status' => $lotStatus,
            'cold_chain_required' => (bool) ($movement['cold_chain_required'] ?? $resolved['cold_chain_required']),
            'observed_temperature' => $movement['observed_temperature'] ?? null,
            'temperature_uom' => $movement['temperature_uom'] ?? null,
            'cold_chain_status' => $movement['cold_chain_status'] ?? null,
            'restriction_reason' => $movement['restriction_reason'] ?? null,
        ]);

        $identity = [
            'category' => $movement['category'],
            'item_type' => $resolved['item_type'],
            'item_id' => $movement['item_id'],
            'branch_id' => $movement['branch_id'],
            'inventory_id' => $movement['inventory_id'],
            'farm_information_id' => $movement['farm_information_id'] ?? null,
            'location' => $movement['location'] ?? 'MAIN',
            'stock_uom_id' => $movement['stock_uom_id'],
            'stock_lot_id' => $stockLot->id,
            'equipment_instance_id' => null,
        ];
        $identityKey = $this->identities->key($identity);

        $balance = InventoryBalance::query()->create($identity + [
            'identity_key' => $identityKey,
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
            'quarantined_quantity' => 0,
            'damaged_quantity' => 0,
            'expired_quantity' => 0,
            'available_quantity' => 0,
            'version' => 1,
        ]);

        $bucket = $this->restrictedBucket($lotStatus);
        $balance->on_hand_quantity = $stockQuantity;
        if ($bucket) {
            $balance->{$bucket} = $stockQuantity;
        }
        $balance->available_quantity = $this->availableQuantity($balance);
        $balance->version++;
        $balance->save();

        $ledger = InventoryLedgerEntry::query()->create([
            'posting_id' => $this->postingId(),
            'posting_batch_id' => $postingBatchId,
            'confirmation_id' => null,
            'source_module' => 'purchasing',
            'source_type' => $movement['source_type'] ?? 'purchase_receipt',
            'source_id' => $movement['source_id'],
            'source_line_id' => $movement['source_line_id'] ?? null,
            'transaction_type' => $movement['transaction_type'] ?? 'purchase_receipt',
            'direction' => 'in',
            'identity_key' => $identityKey,
            'category' => $movement['category'],
            'item_type' => $resolved['item_type'],
            'item_id' => $movement['item_id'],
            'branch_id' => $movement['branch_id'],
            'inventory_id' => $movement['inventory_id'],
            'farm_information_id' => $movement['farm_information_id'] ?? null,
            'location' => $movement['location'] ?? 'MAIN',
            'stock_uom_id' => $movement['stock_uom_id'],
            'stock_lot_id' => $stockLot->id,
            'equipment_instance_id' => null,
            'original_quantity' => $movement['original_quantity'] ?? $stockQuantity,
            'original_uom_id' => $movement['original_uom_id'] ?? $movement['stock_uom_id'],
            'conversion_factor' => $movement['conversion_factor'] ?? 1,
            'quantity_in' => $stockQuantity,
            'quantity_out' => 0,
            'balance_before' => 0,
            'balance_after' => $stockQuantity,
            'posting_status' => 'posted',
            'posted_by_id' => $actor->id,
            'posted_at' => now(),
            'idempotency_key' => $postingBatchId.'-'.($movement['source_line_id'] ?? Str::uuid()->toString()),
            'metadata' => $movement['metadata'] ?? null,
        ]);

        $balance->last_ledger_entry_id = $ledger->id;
        $balance->save();

        return $postingBatchId;
    }
    public function postAdjustment(InventoryAdjustment $adjustment, InventoryConfirmation $confirmation, User $actor): string
    {
        $adjustment->load('lines');
        $postingBatchId = $confirmation->posting_batch_id ?: $this->postingBatchId();

        foreach ($adjustment->lines as $line) {
            $this->postAdjustmentLine($adjustment, $line, $confirmation, $actor, $postingBatchId);
        }

        return $postingBatchId;
    }

    private function postAdjustmentLine(
        InventoryAdjustment $adjustment,
        InventoryAdjustmentLine $line,
        InventoryConfirmation $confirmation,
        User $actor,
        string $postingBatchId,
    ): void {
        $resolved = $this->items->resolve($line->category, (int) $line->item_id);
        $stockLot = $this->resolveStockLot($adjustment, $line, $resolved);
        $equipmentInstance = $this->resolveEquipmentInstance($adjustment, $line, $stockLot, $resolved);

        $identity = [
            'category' => $line->category,
            'item_type' => $line->item_type,
            'item_id' => $line->item_id,
            'branch_id' => $adjustment->branch_id,
            'inventory_id' => $adjustment->inventory_id,
            'farm_information_id' => $adjustment->farm_information_id,
            'location' => $line->location ?: 'MAIN',
            'stock_uom_id' => $line->stock_uom_id,
            'stock_lot_id' => $stockLot?->id,
            'equipment_instance_id' => $equipmentInstance?->id,
        ];
        $identityKey = $this->identities->key($identity);

        $balance = InventoryBalance::query()
            ->where('identity_key', $identityKey)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $balance = InventoryBalance::query()->create($identity + [
                'identity_key' => $identityKey,
                'on_hand_quantity' => 0,
                'reserved_quantity' => 0,
                'quarantined_quantity' => 0,
                'damaged_quantity' => 0,
                'expired_quantity' => 0,
                'available_quantity' => 0,
                'version' => 1,
            ]);
            $balance->refresh();
        }

        $quantity = round(abs((float) $line->adjustment_quantity), 6);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'lines' => 'Adjustment quantity must be greater than zero.',
            ]);
        }

        $direction = $line->direction;
        $before = round((float) $balance->on_hand_quantity, 6);
        $bucket = $this->restrictedBucket($stockLot?->lot_status);

        if ($direction === 'out') {
            $this->assertSufficientStock($balance, $quantity, $line);
            $after = round($before - $quantity, 6);
            $balance->on_hand_quantity = $after;
            if ($bucket) {
                $balance->{$bucket} = max(0, round((float) $balance->{$bucket} - $quantity, 6));
            }
        } else {
            $after = round($before + $quantity, 6);
            $balance->on_hand_quantity = $after;
            if ($bucket) {
                $balance->{$bucket} = round((float) $balance->{$bucket} + $quantity, 6);
            }
        }

        $balance->available_quantity = $this->availableQuantity($balance);
        $balance->version++;
        $balance->save();

        $ledger = InventoryLedgerEntry::query()->create([
            'posting_id' => $this->postingId(),
            'posting_batch_id' => $postingBatchId,
            'confirmation_id' => $confirmation->id,
            'source_module' => 'inventory',
            'source_type' => 'adjustment',
            'source_id' => $adjustment->id,
            'source_line_id' => $line->id,
            'transaction_type' => $adjustment->type,
            'direction' => $direction,
            'identity_key' => $identityKey,
            'category' => $line->category,
            'item_type' => $line->item_type,
            'item_id' => $line->item_id,
            'branch_id' => $adjustment->branch_id,
            'inventory_id' => $adjustment->inventory_id,
            'farm_information_id' => $adjustment->farm_information_id,
            'location' => $line->location ?: 'MAIN',
            'stock_uom_id' => $line->stock_uom_id,
            'stock_lot_id' => $stockLot?->id,
            'equipment_instance_id' => $equipmentInstance?->id,
            'original_quantity' => $quantity,
            'original_uom_id' => $line->stock_uom_id,
            'conversion_factor' => 1,
            'quantity_in' => $direction === 'in' ? $quantity : 0,
            'quantity_out' => $direction === 'out' ? $quantity : 0,
            'balance_before' => $before,
            'balance_after' => $after,
            'posting_status' => 'posted',
            'posted_by_id' => $actor->id,
            'posted_at' => now(),
            'idempotency_key' => $postingBatchId.'-'.$line->id,
            'metadata' => [
                'adjustment_number' => $adjustment->adjustment_number,
                'reason_type' => $adjustment->reason_type,
                'reason' => $adjustment->reason,
            ],
        ]);

        $balance->last_ledger_entry_id = $ledger->id;
        $balance->save();
    }

    /** @param array<string, mixed> $resolved */
    private function resolveStockLot(InventoryAdjustment $adjustment, InventoryAdjustmentLine $line, array $resolved): ?StockLot
    {
        if ($line->stock_lot_id) {
            $lot = StockLot::query()->lockForUpdate()->findOrFail($line->stock_lot_id);
            if ((int) $lot->inventory_id !== (int) $adjustment->inventory_id || (int) $lot->item_id !== (int) $line->item_id || $lot->category !== $line->category) {
                throw ValidationException::withMessages([
                    'stock_lot_id' => 'The selected lot does not match the adjustment item and inventory.',
                ]);
            }

            return $lot;
        }

        if (! in_array($line->category, ['food', 'medicine'], true)) {
            return null;
        }

        if ($line->direction === 'out') {
            throw ValidationException::withMessages([
                'stock_lot_id' => 'Stock-out for food and medicine must target an existing lot.',
            ]);
        }

        if ($resolved['batch_tracking'] && ! $line->receipt_lot_number) {
            throw ValidationException::withMessages([
                'receipt_lot_number' => 'Receipt lot number is required for tracked food and medicine.',
            ]);
        }

        if ($resolved['expiry_tracking'] && ! $line->expiry_date) {
            throw ValidationException::withMessages([
                'expiry_date' => 'Expiry date is required for this item.',
            ]);
        }

        return StockLot::query()->create([
            'category' => $line->category,
            'item_type' => $line->item_type,
            'item_id' => $line->item_id,
            'branch_id' => $adjustment->branch_id,
            'inventory_id' => $adjustment->inventory_id,
            'farm_information_id' => $adjustment->farm_information_id,
            'location' => $line->location ?: 'MAIN',
            'stock_uom_id' => $line->stock_uom_id,
            'supplier_id' => $line->supplier_id,
            'supplier_batch_number' => $line->supplier_batch_number,
            'receipt_lot_number' => $line->receipt_lot_number,
            'manufacturing_date' => $line->manufacturing_date,
            'expiry_date' => $line->expiry_date,
            'lot_status' => $line->lot_status ?: 'available',
            'cold_chain_required' => $resolved['cold_chain_required'],
            'cold_chain_status' => $resolved['cold_chain_required'] ? 'unknown' : null,
        ]);
    }

    /** @param array<string, mixed> $resolved */
    private function resolveEquipmentInstance(InventoryAdjustment $adjustment, InventoryAdjustmentLine $line, ?StockLot $stockLot, array $resolved): ?EquipmentInstance
    {
        if ($line->category !== 'equipment') {
            return null;
        }

        if ($line->equipment_instance_id) {
            $instance = EquipmentInstance::query()->lockForUpdate()->findOrFail($line->equipment_instance_id);
            if ((int) $instance->inventory_id !== (int) $adjustment->inventory_id || (int) $instance->equipment_id !== (int) $resolved['model']->id) {
                throw ValidationException::withMessages([
                    'equipment_instance_id' => 'The selected equipment instance does not match the adjustment item and inventory.',
                ]);
            }

            return $instance;
        }

        if (! $line->serial_number && ! $line->asset_tag) {
            return null;
        }

        if ($line->direction === 'out') {
            throw ValidationException::withMessages([
                'equipment_instance_id' => 'Serialized equipment stock-out must target an existing equipment instance.',
            ]);
        }

        if (round(abs((float) $line->adjustment_quantity), 6) !== 1.0) {
            throw ValidationException::withMessages([
                'adjustment_quantity' => 'Serialized equipment adjustments must use a quantity of 1.',
            ]);
        }

        return EquipmentInstance::query()->create([
            'equipment_id' => $resolved['model']->id,
            'branch_id' => $adjustment->branch_id,
            'inventory_id' => $adjustment->inventory_id,
            'farm_information_id' => $adjustment->farm_information_id,
            'stock_lot_id' => $stockLot?->id,
            'location' => $line->location ?: 'MAIN',
            'serial_number' => $line->serial_number,
            'asset_tag' => $line->asset_tag,
            'condition' => $line->condition ?: 'good',
            'lifecycle_status' => $line->lifecycle_status ?: 'available',
            'source_reference' => $adjustment->adjustment_number,
        ]);
    }

    private function assertSufficientStock(InventoryBalance $balance, float $quantity, InventoryAdjustmentLine $line): void
    {
        if ((float) $balance->on_hand_quantity < $quantity) {
            throw ValidationException::withMessages([
                'lines' => "Line {$line->line_number} would create negative on-hand stock.",
            ]);
        }

        if ((float) $balance->available_quantity < $quantity && ! $this->restrictedBucket($line->stockLot?->lot_status)) {
            throw ValidationException::withMessages([
                'lines' => "Line {$line->line_number} would create negative available stock.",
            ]);
        }
    }

    private function availableQuantity(InventoryBalance $balance): float
    {
        return round(max(0, (float) $balance->on_hand_quantity
            - (float) $balance->reserved_quantity
            - (float) $balance->quarantined_quantity
            - (float) $balance->damaged_quantity
            - (float) $balance->expired_quantity), 6);
    }

    private function restrictedBucket(?string $lotStatus): ?string
    {
        return match ($lotStatus) {
            'quarantined', 'recalled', 'blocked' => 'quarantined_quantity',
            'damaged' => 'damaged_quantity',
            'expired' => 'expired_quantity',
            default => null,
        };
    }

    private function postingBatchId(): string
    {
        return 'IPB-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }

    private function postingId(): string
    {
        return 'LED-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
