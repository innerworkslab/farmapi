<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'on_hand_quantity',
        'reserved_quantity',
        'quarantined_quantity',
        'damaged_quantity',
        'expired_quantity',
        'available_quantity',
        'version',
        'last_ledger_entry_id',
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
}
