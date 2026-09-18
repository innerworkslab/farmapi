<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'posting_id',
        'posting_batch_id',
        'confirmation_id',
        'source_module',
        'source_type',
        'source_id',
        'source_line_id',
        'transaction_type',
        'direction',
        'identity_key',
        'category',
        'item_type',
        'item_id',
        'branch_id',
        'inventory_id',
        'farm_information_id',
        'location',
        'stock_uom_id',
        'stock_lot_id',
        'equipment_instance_id',
        'original_quantity',
        'original_uom_id',
        'conversion_factor',
        'quantity_in',
        'quantity_out',
        'balance_before',
        'balance_after',
        'posting_status',
        'posted_by_id',
        'posted_at',
        'original_ledger_entry_id',
        'idempotency_key',
        'metadata',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function equipmentInstance(): BelongsTo
    {
        return $this->belongsTo(EquipmentInstance::class, 'equipment_instance_id');
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
