<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Setup\Models\Inventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    public function __construct(
        private readonly InventoryItemResolver $items,
        private readonly InventoryPostingService $posting,
        private readonly InventoryWorkflowService $workflow,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return InventoryAdjustment::query()
            ->with(['lines', 'confirmation'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where('adjustment_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['inventory_id'] ?? null, fn (Builder $query, string $inventoryId) => $query->where('inventory_id', $inventoryId))
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($data, $actor): InventoryAdjustment {
            $inventory = Inventory::query()->findOrFail($data['inventory_id']);
            $this->assertBranchMatchesInventory((int) $data['branch_id'], $inventory);

            $adjustment = InventoryAdjustment::query()->create([
                'adjustment_number' => $data['adjustment_number'] ?? $this->adjustmentNumber(),
                'type' => $data['type'],
                'adjustment_date' => $data['adjustment_date'],
                'branch_id' => $data['branch_id'],
                'inventory_id' => $data['inventory_id'],
                'farm_information_id' => $data['farm_information_id'] ?? null,
                'status' => InventoryAdjustment::STATUS_DRAFT,
                'reason_type' => $data['reason_type'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            $this->replaceLines($adjustment, $data['lines']);
            $this->workflow->record($adjustment, 'created', null, $adjustment->status, $actor);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(InventoryAdjustment $adjustment, array $data, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data, $actor): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if (! in_array($adjustment->status, [InventoryAdjustment::STATUS_DRAFT, InventoryAdjustment::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or rejected adjustments can be edited.',
                ]);
            }

            if (isset($data['inventory_id'])) {
                $inventory = Inventory::query()->findOrFail($data['inventory_id']);
                $this->assertBranchMatchesInventory((int) ($data['branch_id'] ?? $adjustment->branch_id), $inventory);
            }

            $adjustment->fill([
                'type' => $data['type'] ?? $adjustment->type,
                'adjustment_date' => $data['adjustment_date'] ?? $adjustment->adjustment_date,
                'branch_id' => $data['branch_id'] ?? $adjustment->branch_id,
                'inventory_id' => $data['inventory_id'] ?? $adjustment->inventory_id,
                'farm_information_id' => $data['farm_information_id'] ?? $adjustment->farm_information_id,
                'status' => InventoryAdjustment::STATUS_DRAFT,
                'reason_type' => $data['reason_type'] ?? $adjustment->reason_type,
                'reason' => $data['reason'] ?? $adjustment->reason,
                'notes' => $data['notes'] ?? $adjustment->notes,
                'attachment_path' => $data['attachment_path'] ?? $adjustment->attachment_path,
                'rejection_reason' => null,
                'rejected_by_id' => null,
                'rejected_at' => null,
            ]);
            $adjustment->version++;
            $adjustment->save();

            if (isset($data['lines'])) {
                $adjustment->lines()->delete();
                $this->replaceLines($adjustment, $data['lines']);
            }

            $this->workflow->record($adjustment, 'updated', null, $adjustment->status, $actor);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    public function submit(InventoryAdjustment $adjustment, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->with('lines')->lockForUpdate()->findOrFail($adjustment->id);
            if (! in_array($adjustment->status, [InventoryAdjustment::STATUS_DRAFT, InventoryAdjustment::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or rejected adjustments can be submitted.',
                ]);
            }

            if ($adjustment->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'At least one adjustment line is required.',
                ]);
            }

            $from = $adjustment->status;
            $adjustment->fill([
                'status' => InventoryAdjustment::STATUS_SUBMITTED,
                'submitted_by_id' => $actor->id,
                'submitted_at' => now(),
                'version' => $adjustment->version + 1,
            ])->save();

            InventoryConfirmation::query()->where([
                'source_type' => 'adjustment',
                'source_id' => $adjustment->id,
                'status' => InventoryConfirmation::STATUS_PENDING,
            ])->delete();

            InventoryConfirmation::query()->create([
                'confirmation_number' => $this->confirmationNumber(),
                'source_module' => 'inventory',
                'source_type' => 'adjustment',
                'source_id' => $adjustment->id,
                'submission_version' => $adjustment->version,
                'status' => InventoryConfirmation::STATUS_PENDING,
                'submitted_by_id' => $actor->id,
                'submitted_at' => now(),
                'payload_snapshot' => $adjustment->refresh()->load('lines')->toArray(),
                'validation_snapshot' => ['message' => 'Pending confirmation revalidation required before posting.'],
            ]);

            $this->workflow->record($adjustment, 'submitted', $from, $adjustment->status, $actor);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    public function confirm(InventoryAdjustment $adjustment, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->with(['lines', 'confirmation'])->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== InventoryAdjustment::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted adjustments can be confirmed.',
                ]);
            }

            $confirmation = InventoryConfirmation::query()
                ->where('source_type', 'adjustment')
                ->where('source_id', $adjustment->id)
                ->where('status', InventoryConfirmation::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $postingBatchId = $this->posting->postAdjustment($adjustment, $confirmation, $actor);
            $from = $adjustment->status;

            $confirmation->fill([
                'status' => InventoryConfirmation::STATUS_CONFIRMED,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'posting_batch_id' => $postingBatchId,
            ])->save();

            $adjustment->fill([
                'status' => InventoryAdjustment::STATUS_CONFIRMED,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'posting_batch_id' => $postingBatchId,
                'version' => $adjustment->version + 1,
            ])->save();

            $this->workflow->record($adjustment, 'confirmed', $from, $adjustment->status, $actor, null, [
                'posting_batch_id' => $postingBatchId,
                'confirmation_id' => $confirmation->id,
            ]);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    public function reject(InventoryAdjustment $adjustment, string $reason, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $actor): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== InventoryAdjustment::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted adjustments can be rejected.',
                ]);
            }

            $confirmation = InventoryConfirmation::query()
                ->where('source_type', 'adjustment')
                ->where('source_id', $adjustment->id)
                ->where('status', InventoryConfirmation::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $adjustment->status;
            $confirmation->fill([
                'status' => InventoryConfirmation::STATUS_REJECTED,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $adjustment->fill([
                'status' => InventoryAdjustment::STATUS_REJECTED,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'version' => $adjustment->version + 1,
            ])->save();

            $this->workflow->record($adjustment, 'rejected', $from, $adjustment->status, $actor, $reason);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    public function reverse(InventoryAdjustment $adjustment, string $reason, User $actor): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $actor): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->with('lines')->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== InventoryAdjustment::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => 'Only confirmed adjustments can be reversed.',
                ]);
            }

            $reversal = InventoryAdjustment::query()->create([
                'adjustment_number' => $this->adjustmentNumber('REV'),
                'type' => 'reversal',
                'adjustment_date' => now()->toDateString(),
                'branch_id' => $adjustment->branch_id,
                'inventory_id' => $adjustment->inventory_id,
                'farm_information_id' => $adjustment->farm_information_id,
                'status' => InventoryAdjustment::STATUS_DRAFT,
                'reason_type' => 'reversal',
                'reason' => $reason,
                'created_by_id' => $actor->id,
                'original_adjustment_id' => $adjustment->id,
            ]);

            foreach ($adjustment->lines as $line) {
                $reversal->lines()->create([
                    'line_number' => $line->line_number,
                    'category' => $line->category,
                    'item_type' => $line->item_type,
                    'item_id' => $line->item_id,
                    'location' => $line->location,
                    'stock_uom_id' => $line->stock_uom_id,
                    'stock_lot_id' => $line->stock_lot_id,
                    'equipment_instance_id' => $line->equipment_instance_id,
                    'system_quantity' => 0,
                    'adjustment_quantity' => $line->adjustment_quantity,
                    'direction' => $line->direction === 'in' ? 'out' : 'in',
                    'new_identity' => false,
                    'metadata' => ['reverses_adjustment_line_id' => $line->id],
                ]);
            }

            $this->workflow->record($reversal, 'created', null, InventoryAdjustment::STATUS_DRAFT, $actor, $reason, [
                'original_adjustment_id' => $adjustment->id,
            ]);

            $reversal = $this->submit($reversal, $actor);
            $reversal = $this->confirm($reversal, $actor);

            $from = $adjustment->status;
            $adjustment->fill([
                'status' => InventoryAdjustment::STATUS_REVERSED,
                'version' => $adjustment->version + 1,
            ])->save();
            $this->workflow->record($adjustment, 'reversed', $from, $adjustment->status, $actor, $reason, [
                'reversal_adjustment_id' => $reversal->id,
            ]);

            return $adjustment->refresh()->load(['lines', 'confirmation']);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(InventoryAdjustment $adjustment, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $resolved = $this->items->resolve($line['category'], (int) $line['item_id']);
            $stockUomId = $line['stock_uom_id'] ?? $resolved['stock_uom_id'];
            if (! $stockUomId) {
                throw ValidationException::withMessages([
                    "lines.{$index}.stock_uom_id" => 'Stock UOM is required for this item.',
                ]);
            }

            $quantity = $this->lineQuantity($line);
            $direction = $quantity < 0 ? 'out' : 'in';

            $adjustment->lines()->create([
                'line_number' => $line['line_number'] ?? ($index + 1),
                'category' => $this->items->normalizeCategory($line['category']),
                'item_type' => $resolved['item_type'],
                'item_id' => $line['item_id'],
                'location' => $line['location'] ?? 'MAIN',
                'stock_uom_id' => $stockUomId,
                'stock_lot_id' => $line['stock_lot_id'] ?? null,
                'equipment_instance_id' => $line['equipment_instance_id'] ?? null,
                'system_quantity' => $line['system_quantity'] ?? 0,
                'counted_quantity' => $line['counted_quantity'] ?? null,
                'adjustment_quantity' => abs($quantity),
                'direction' => $line['direction'] ?? $direction,
                'new_identity' => $line['new_identity'] ?? false,
                'supplier_id' => $line['supplier_id'] ?? null,
                'supplier_batch_number' => $line['supplier_batch_number'] ?? null,
                'receipt_lot_number' => $line['receipt_lot_number'] ?? null,
                'manufacturing_date' => $line['manufacturing_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'lot_status' => $line['lot_status'] ?? null,
                'serial_number' => $line['serial_number'] ?? null,
                'asset_tag' => $line['asset_tag'] ?? null,
                'condition' => $line['condition'] ?? null,
                'lifecycle_status' => $line['lifecycle_status'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $line */
    private function lineQuantity(array $line): float
    {
        if (array_key_exists('adjustment_quantity', $line) && $line['adjustment_quantity'] !== null) {
            $quantity = (float) $line['adjustment_quantity'];
            if (($line['direction'] ?? null) === 'out') {
                return -abs($quantity);
            }

            if (($line['direction'] ?? null) === 'in') {
                return abs($quantity);
            }

            return $quantity;
        }

        if (array_key_exists('counted_quantity', $line) && array_key_exists('system_quantity', $line)) {
            return (float) $line['counted_quantity'] - (float) $line['system_quantity'];
        }

        throw ValidationException::withMessages([
            'lines' => 'Each line requires adjustment quantity or counted/system quantity.',
        ]);
    }

    private function assertBranchMatchesInventory(int $branchId, Inventory $inventory): void
    {
        if ((int) $inventory->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Inventory must belong to the selected branch.',
            ]);
        }
    }

    private function adjustmentNumber(string $prefix = 'ADJ'): string
    {
        return $prefix.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }

    private function confirmationNumber(): string
    {
        return 'CNF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }
}
