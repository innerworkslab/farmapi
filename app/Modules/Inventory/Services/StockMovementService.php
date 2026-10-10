<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Used inside the source service's database transaction with a locked balance. */
class StockMovementService
{
    public function post(InventoryBalance $balance, float $delta, string $source, int $sourceId, User $actor, string $key, array $metadata = []): InventoryLedgerEntry
    {
        $before = (float) $balance->on_hand_quantity;
        $after = round($before + $delta, 6);
        $restricted = (float) $balance->reserved_quantity + (float) $balance->quarantined_quantity + (float) $balance->damaged_quantity + (float) $balance->expired_quantity;
        if ($after < 0 || ($delta < 0 && $after < $restricted && $source !== 'mortality')) {
            throw ValidationException::withMessages(['quantity' => 'Quantity exceeds eligible stock.']);
        }
        if ($source === 'mortality' && $after < (float) $balance->reserved_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Release animal reservations before recording this mortality.']);
        }
        $ledger = InventoryLedgerEntry::query()->create([
            'posting_id' => 'MOV-'.Str::uuid(), 'posting_batch_id' => $source.'-'.$sourceId,
            'source_module' => in_array($source, ['animal_receipt', 'equipment_receipt']) ? 'purchasing' : 'farms',
            'source_type' => $source, 'source_id' => $sourceId, 'transaction_type' => $source,
            'direction' => $delta < 0 ? 'out' : 'in', 'identity_key' => $balance->identity_key,
            'category' => $balance->category, 'item_type' => $balance->item_type, 'item_id' => $balance->item_id,
            'branch_id' => $balance->branch_id, 'inventory_id' => $balance->inventory_id,
            'farm_information_id' => $balance->farm_information_id, 'location' => $balance->location,
            'stock_uom_id' => $balance->stock_uom_id, 'stock_lot_id' => $balance->stock_lot_id,
            'equipment_instance_id' => $balance->equipment_instance_id, 'original_quantity' => abs($delta),
            'original_uom_id' => $balance->stock_uom_id, 'conversion_factor' => 1,
            'quantity_in' => max(0, $delta), 'quantity_out' => max(0, -$delta),
            'balance_before' => $before, 'balance_after' => $after, 'posting_status' => 'posted',
            'posted_by_id' => $actor->id, 'posted_at' => now(), 'idempotency_key' => $key, 'metadata' => $metadata,
        ]);
        $balance->on_hand_quantity = $after;
        if ($source === 'mortality') {
            $remaining = max(0, $after - (float) $balance->reserved_quantity);
            foreach (['quarantined_quantity', 'damaged_quantity', 'expired_quantity'] as $field) {
                $balance->{$field} = min((float) $balance->{$field}, $remaining);
                $remaining -= (float) $balance->{$field};
            }
        }
        $balance->available_quantity = max(0, $after - (float) $balance->reserved_quantity - (float) $balance->quarantined_quantity - (float) $balance->damaged_quantity - (float) $balance->expired_quantity);
        $balance->last_ledger_entry_id = $ledger->id;
        $balance->version++;
        $balance->save();

        return $ledger;
    }
}
