<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockLot extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'inventory_stock_lots';

    protected $fillable = [
        'category',
        'item_type',
        'item_id',
        'branch_id',
        'inventory_id',
        'farm_information_id',
        'location',
        'stock_uom_id',
        'supplier_id',
        'supplier_batch_number',
        'receipt_lot_number',
        'manufacturing_date',
        'expiry_date',
        'lot_status',
        'cold_chain_required',
        'min_temperature',
        'max_temperature',
        'observed_temperature',
        'temperature_uom',
        'cold_chain_status',
        'restriction_reason',
        'version',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inventory')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'cold_chain_required' => 'boolean',
        ];
    }
}
