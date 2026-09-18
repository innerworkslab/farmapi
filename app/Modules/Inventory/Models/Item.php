<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Item extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'itemable_type',
        'itemable_id',
        'code',
        'name',
        'category',
        'master_category',
        'stock_uom_id',
        'purchase_uom_id',
        'usage_uom_id',
        'uom_conversion',
        'batch_tracking',
        'expiry_tracking',
        'serial_tracking',
        'asset_tracking',
        'cold_chain_required',
        'divisible_quantity',
        'minimum_stock_level',
        'maximum_stock_level',
        'reorder_level',
        'reorder_quantity',
        'status',
        'source_version',
        'version',
    ];

    public function itemable(): MorphTo
    {
        return $this->morphTo();
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
            'uom_conversion' => 'decimal:6',
            'batch_tracking' => 'boolean',
            'expiry_tracking' => 'boolean',
            'serial_tracking' => 'boolean',
            'asset_tracking' => 'boolean',
            'cold_chain_required' => 'boolean',
            'divisible_quantity' => 'boolean',
            'minimum_stock_level' => 'decimal:3',
            'maximum_stock_level' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'reorder_quantity' => 'decimal:3',
        ];
    }
}
