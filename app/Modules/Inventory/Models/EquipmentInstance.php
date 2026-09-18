<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EquipmentInstance extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'inventory_equipment_instances';

    protected $fillable = [
        'equipment_id',
        'branch_id',
        'inventory_id',
        'farm_information_id',
        'stock_lot_id',
        'location',
        'serial_number',
        'asset_tag',
        'condition',
        'lifecycle_status',
        'assignee_user_id',
        'source_reference',
        'version',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inventory')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
