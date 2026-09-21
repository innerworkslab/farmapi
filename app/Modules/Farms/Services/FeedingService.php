<?php

namespace App\Modules\Farms\Services;

use App\Models\User;
use App\Modules\Farms\Models\FeedingLine;
use App\Modules\Farms\Models\FeedingRecord;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\InventoryItemResolver;
use App\Modules\Inventory\Services\StockIdentityService;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedingService
{
    public function __construct(
        private readonly InventoryItemResolver $items,
        private readonly StockIdentityService $identities,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return FeedingRecord::query()
            ->with(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation'])
            ->when($filters['farm_information_id'] ?? null, fn (Builder $query, string $farmId) => $query->where('farm_information_id', $farmId))
            ->when($filters['animal_balance_id'] ?? null, fn (Builder $query, string $animalBalanceId) => $query->where('animal_balance_id', $animalBalanceId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('feeding_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('feeding_date', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('feeding_number', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('feeding_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): FeedingRecord
    {
        return DB::transaction(function () use ($data, $actor): FeedingRecord {
            $target = $this->resolveTarget($data);

            $record = FeedingRecord::query()->create([
                'feeding_number' => $data['feeding_number'] ?? $this->feedingNumber(),
                'feeding_date' => $data['feeding_date'],
                'feeding_time' => $data['feeding_time'] ?? null,
                'branch_id' => $data['branch_id'],
                'farm_information_id' => $data['farm_information_id'],
                'animal_balance_id' => $target['balance']->id,
                'animal_item_id' => $target['balance']->item_id,
                'animal_id' => $target['animal']?->id,
                'target_type' => $target['tracking_type'],
                'animal_type' => $target['animal']?->type,
                'breed' => $target['animal']?->breed,
                'animal_count' => $target['animal_count'],
                'location' => $target['balance']->location,
                'status' => FeedingRecord::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            $this->replaceLines($record, $data['lines']);

            return $record->refresh()->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(FeedingRecord $feeding, array $data, User $actor): FeedingRecord
    {
        return DB::transaction(function () use ($feeding, $data): FeedingRecord {
            $feeding = FeedingRecord::query()->with('lines')->lockForUpdate()->findOrFail($feeding->id);

            if (! in_array($feeding->status, [FeedingRecord::STATUS_DRAFT, FeedingRecord::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or rejected feeding records can be edited.']);
            }

            $target = $this->resolveTarget($data);
            $feeding->fill([
                'feeding_date' => $data['feeding_date'],
                'feeding_time' => $data['feeding_time'] ?? null,
                'branch_id' => $data['branch_id'],
                'farm_information_id' => $data['farm_information_id'],
                'animal_balance_id' => $target['balance']->id,
                'animal_item_id' => $target['balance']->item_id,
                'animal_id' => $target['animal']?->id,
                'target_type' => $target['tracking_type'],
                'animal_type' => $target['animal']?->type,
                'breed' => $target['animal']?->breed,
                'animal_count' => $target['animal_count'],
                'location' => $target['balance']->location,
                'status' => FeedingRecord::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'rejection_reason' => null,
                'rejected_by_id' => null,
                'rejected_at' => null,
                'version' => $feeding->version + 1,
            ])->save();

            $feeding->lines()->delete();
            $this->replaceLines($feeding, $data['lines']);

            return $feeding->refresh()->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']);
        });
    }

    public function submit(FeedingRecord $feeding, User $actor): FeedingRecord
    {
        return DB::transaction(function () use ($feeding, $actor): FeedingRecord {
            $feeding = FeedingRecord::query()->with('lines')->lockForUpdate()->findOrFail($feeding->id);

            if (! in_array($feeding->status, [FeedingRecord::STATUS_DRAFT, FeedingRecord::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or rejected feeding records can be submitted.']);
            }

            if ($feeding->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'At least one feeding line is required.']);
            }

            $this->revalidateRecord($feeding);

            $from = $feeding->status;
            $feeding->fill([
                'status' => FeedingRecord::STATUS_SUBMITTED,
                'submitted_by_id' => $actor->id,
                'submitted_at' => now(),
                'version' => $feeding->version + 1,
            ])->save();

            InventoryConfirmation::query()->where([
                'source_module' => 'farms',
                'source_type' => 'feeding',
                'source_id' => $feeding->id,
                'status' => InventoryConfirmation::STATUS_PENDING,
            ])->delete();

            InventoryConfirmation::query()->create([
                'confirmation_number' => $this->confirmationNumber(),
                'source_module' => 'farms',
                'source_type' => 'feeding',
                'source_id' => $feeding->id,
                'submission_version' => $feeding->version,
                'status' => InventoryConfirmation::STATUS_PENDING,
                'submitted_by_id' => $actor->id,
                'submitted_at' => now(),
                'payload_snapshot' => $feeding->refresh()->load('lines')->toArray(),
                'validation_snapshot' => [
                    'message' => 'Pending feeding confirmation revalidation required before stock posting.',
                    'from_status' => $from,
                ],
            ]);

            return $feeding->refresh()->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']);
        });
    }

    public function confirm(FeedingRecord $feeding, User $actor): FeedingRecord
    {
        return DB::transaction(function () use ($feeding, $actor): FeedingRecord {
            $feeding = FeedingRecord::query()->with(['lines.stockLot', 'confirmation'])->lockForUpdate()->findOrFail($feeding->id);

            if ($feeding->status !== FeedingRecord::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only submitted feeding records can be confirmed.']);
            }

            $confirmation = InventoryConfirmation::query()
                ->where('source_module', 'farms')
                ->where('source_type', 'feeding')
                ->where('source_id', $feeding->id)
                ->where('status', InventoryConfirmation::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $postingBatchId = $confirmation->posting_batch_id ?: $this->postingBatchId();
            $this->postFeedConsumption($feeding, $confirmation, $actor, $postingBatchId);

            $confirmation->fill([
                'status' => InventoryConfirmation::STATUS_CONFIRMED,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'posting_batch_id' => $postingBatchId,
            ])->save();

            $feeding->fill([
                'status' => FeedingRecord::STATUS_CONFIRMED,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'posting_batch_id' => $postingBatchId,
                'version' => $feeding->version + 1,
            ])->save();

            return $feeding->refresh()->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']);
        });
    }

    public function reject(FeedingRecord $feeding, string $reason, User $actor): FeedingRecord
    {
        return DB::transaction(function () use ($feeding, $reason, $actor): FeedingRecord {
            $feeding = FeedingRecord::query()->lockForUpdate()->findOrFail($feeding->id);

            if ($feeding->status !== FeedingRecord::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only submitted feeding records can be rejected.']);
            }

            $confirmation = InventoryConfirmation::query()
                ->where('source_module', 'farms')
                ->where('source_type', 'feeding')
                ->where('source_id', $feeding->id)
                ->where('status', InventoryConfirmation::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $confirmation->fill([
                'status' => InventoryConfirmation::STATUS_REJECTED,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $feeding->fill([
                'status' => FeedingRecord::STATUS_REJECTED,
                'rejected_by_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'version' => $feeding->version + 1,
            ])->save();

            return $feeding->refresh()->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']);
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveTarget(array $data): array
    {
        $farm = FarmInformation::query()->findOrFail($data['farm_information_id']);
        if ((int) $farm->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages(['farm_information_id' => 'Farm must belong to the selected branch.']);
        }

        $balance = InventoryBalance::query()
            ->with(['item.itemable'])
            ->where('category', 'animal')
            ->where('farm_information_id', $farm->id)
            ->where('on_hand_quantity', '>', 0)
            ->findOrFail($data['animal_balance_id']);

        $animal = $balance->item?->itemable;
        $trackingType = $animal?->tracking_type;

        if (! in_array($trackingType, ['batch', 'individual'], true)) {
            throw ValidationException::withMessages(['animal_balance_id' => 'Selected animal balance must be batch or individually tracked.']);
        }

        $animalCount = $trackingType === 'individual' ? 1.0 : (float) $balance->on_hand_quantity;
        if ($animalCount <= 0) {
            throw ValidationException::withMessages(['animal_balance_id' => 'Selected animal balance has no active animals.']);
        }

        return compact('farm', 'balance', 'animal', 'trackingType') + [
            'tracking_type' => $trackingType,
            'animal_count' => $animalCount,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(FeedingRecord $feeding, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $resolved = $this->items->resolve('food', (int) $line['food_item_id']);
            $stockUomId = $line['stock_uom_id'] ?? $resolved['stock_uom_id'];
            if (! $stockUomId) {
                throw ValidationException::withMessages(["lines.{$index}.stock_uom_id" => 'Stock UOM is required for this food item.']);
            }

            if ($resolved['batch_tracking'] && empty($line['stock_lot_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Food batch is required for batch-tracked food.']);
            }

            $stockLot = $this->validateStockLot($line, $feeding, $index);
            $sourceLocation = $line['source_location'] ?? $stockLot?->location ?? 'MAIN';
            $quantity = round((float) $line['quantity'], 6);
            $wastage = round((float) ($line['wastage_quantity'] ?? 0), 6);
            $quantityPerAnimal = round($quantity / max(1, (float) $feeding->animal_count), 6);

            $feeding->lines()->create([
                'line_number' => $line['line_number'] ?? ($index + 1),
                'food_item_id' => $line['food_item_id'],
                'inventory_id' => $line['inventory_id'],
                'stock_lot_id' => $line['stock_lot_id'] ?? null,
                'source_location' => $sourceLocation,
                'stock_uom_id' => $stockUomId,
                'quantity' => $quantity,
                'wastage_quantity' => $wastage,
                'quantity_per_animal' => $quantityPerAnimal,
                'notes' => $line['notes'] ?? null,
                'metadata' => [
                    'fefo_recommended_stock_lot_id' => $this->fefoLotId((int) $line['food_item_id'], (int) $line['inventory_id'], $sourceLocation),
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $line */
    private function validateStockLot(array $line, FeedingRecord $feeding, int $index): ?StockLot
    {
        $inventory = Inventory::query()->findOrFail($line['inventory_id']);
        if ((int) $inventory->branch_id !== (int) $feeding->branch_id) {
            throw ValidationException::withMessages(["lines.{$index}.inventory_id" => 'Source inventory must belong to the feeding branch.']);
        }

        if (empty($line['stock_lot_id'])) {
            return null;
        }

        $lot = StockLot::query()->findOrFail($line['stock_lot_id']);
        if ($lot->category !== 'food' || (int) $lot->item_id !== (int) $line['food_item_id'] || (int) $lot->inventory_id !== (int) $line['inventory_id']) {
            throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Food batch does not match the selected food item and source inventory.']);
        }

        if ($lot->lot_status !== 'available') {
            throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Only available food batches can be consumed.']);
        }

        if ($lot->expiry_date && $lot->expiry_date->isBefore(now()->toDateString())) {
            throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Expired food cannot be used for feeding.']);
        }

        return $lot;
    }

    private function revalidateRecord(FeedingRecord $feeding): void
    {
        $feeding->load('lines');
        foreach ($feeding->lines as $index => $line) {
            $this->assertAvailableForLine($feeding, $line, $index);
        }
    }

    private function postFeedConsumption(FeedingRecord $feeding, InventoryConfirmation $confirmation, User $actor, string $postingBatchId): void
    {
        $feeding->load('lines.stockLot');
        foreach ($feeding->lines as $index => $line) {
            $balance = $this->assertAvailableForLine($feeding, $line, $index);
            $quantity = round((float) $line->quantity, 6);
            $before = round((float) $balance->on_hand_quantity, 6);
            $after = round($before - $quantity, 6);

            $balance->on_hand_quantity = $after;
            $balance->available_quantity = $this->availableQuantity($balance);
            if ($balance->available_quantity < 0) {
                $balance->available_quantity = 0;
            }
            $balance->version++;
            $balance->save();

            $ledger = InventoryLedgerEntry::query()->create([
                'posting_id' => $this->postingId(),
                'posting_batch_id' => $postingBatchId,
                'confirmation_id' => $confirmation->id,
                'source_module' => 'farms',
                'source_type' => 'feeding',
                'source_id' => $feeding->id,
                'source_line_id' => $line->id,
                'transaction_type' => 'feed_consumption',
                'direction' => 'out',
                'identity_key' => $balance->identity_key,
                'category' => 'food',
                'item_type' => 'food',
                'item_id' => $line->food_item_id,
                'branch_id' => $feeding->branch_id,
                'inventory_id' => $line->inventory_id,
                'farm_information_id' => $feeding->farm_information_id,
                'location' => $line->source_location,
                'stock_uom_id' => $line->stock_uom_id,
                'stock_lot_id' => $line->stock_lot_id,
                'equipment_instance_id' => null,
                'original_quantity' => $quantity,
                'original_uom_id' => $line->stock_uom_id,
                'conversion_factor' => 1,
                'quantity_in' => 0,
                'quantity_out' => $quantity,
                'balance_before' => $before,
                'balance_after' => $after,
                'posting_status' => 'posted',
                'posted_by_id' => $actor->id,
                'posted_at' => now(),
                'idempotency_key' => $postingBatchId.'-'.$line->id,
                'metadata' => [
                    'feeding_number' => $feeding->feeding_number,
                    'animal_balance_id' => $feeding->animal_balance_id,
                    'animal_count' => (float) $feeding->animal_count,
                    'quantity_per_animal' => (float) $line->quantity_per_animal,
                    'wastage_quantity' => (float) $line->wastage_quantity,
                ],
            ]);

            $balance->last_ledger_entry_id = $ledger->id;
            $balance->save();
        }
    }

    private function assertAvailableForLine(FeedingRecord $feeding, FeedingLine $line, int $index): InventoryBalance
    {
        $stockLot = $line->stockLot;
        if ($stockLot) {
            if ($stockLot->lot_status !== 'available') {
                throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Only available food batches can be consumed.']);
            }

            if ($stockLot->expiry_date && $stockLot->expiry_date->isBefore(now()->toDateString())) {
                throw ValidationException::withMessages(["lines.{$index}.stock_lot_id" => 'Expired food cannot be used for feeding.']);
            }
        }

        $identity = [
            'category' => 'food',
            'item_type' => 'food',
            'item_id' => $line->food_item_id,
            'branch_id' => $feeding->branch_id,
            'inventory_id' => $line->inventory_id,
            'farm_information_id' => $feeding->farm_information_id,
            'location' => $line->source_location ?: 'MAIN',
            'stock_uom_id' => $line->stock_uom_id,
            'stock_lot_id' => $line->stock_lot_id,
            'equipment_instance_id' => null,
        ];

        $balance = InventoryBalance::query()
            ->where('identity_key', $this->identities->key($identity))
            ->lockForUpdate()
            ->first();

        if (! $balance || (float) $balance->available_quantity < (float) $line->quantity) {
            throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Quantity cannot exceed eligible available food stock.']);
        }

        return $balance;
    }

    private function availableQuantity(InventoryBalance $balance): float
    {
        return round(max(0, (float) $balance->on_hand_quantity
            - (float) $balance->reserved_quantity
            - (float) $balance->quarantined_quantity
            - (float) $balance->damaged_quantity
            - (float) $balance->expired_quantity), 6);
    }

    private function fefoLotId(int $foodItemId, int $inventoryId, string $location): ?int
    {
        return StockLot::query()
            ->where('category', 'food')
            ->where('item_id', $foodItemId)
            ->where('inventory_id', $inventoryId)
            ->where('location', $location)
            ->where('lot_status', 'available')
            ->where(function (Builder $query): void {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->value('id');
    }

    private function feedingNumber(): string
    {
        return 'FED-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }

    private function confirmationNumber(): string
    {
        return 'FCF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
    }

    private function postingBatchId(): string
    {
        return 'FPB-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }

    private function postingId(): string
    {
        return 'LED-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}