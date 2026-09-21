<?php

namespace App\Modules\Farms\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedingLine extends Model
{
    use HasFactory;

    protected $table = 'farm_feeding_lines';

    protected $fillable = [
        'feeding_record_id',
        'line_number',
        'food_item_id',
        'inventory_id',
        'stock_lot_id',
        'source_location',
        'stock_uom_id',
        'quantity',
        'wastage_quantity',
        'quantity_per_animal',
        'notes',
        'metadata',
    ];

    public function feedingRecord(): BelongsTo
    {
        return $this->belongsTo(FeedingRecord::class, 'feeding_record_id');
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'food_item_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'wastage_quantity' => 'decimal:6',
            'quantity_per_animal' => 'decimal:6',
            'metadata' => 'array',
        ];
    }
}