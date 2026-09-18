<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'adjustment_id',
        'line_number',
        'category',
        'item_type',
        'item_id',
        'location',
        'stock_uom_id',
        'stock_lot_id',
        'equipment_instance_id',
        'system_quantity',
        'counted_quantity',
        'adjustment_quantity',
        'direction',
        'new_identity',
        'supplier_id',
        'supplier_batch_number',
        'receipt_lot_number',
        'manufacturing_date',
        'expiry_date',
        'lot_status',
        'serial_number',
        'asset_tag',
        'condition',
        'lifecycle_status',
        'metadata',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
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
            'new_identity' => 'boolean',
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'metadata' => 'array',
        ];
    }
}
