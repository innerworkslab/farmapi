<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Food extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'foods';

    protected $fillable = [
        'code',
        'name',
        'category',
        'type',
        'target_animal_type',
        'life_stage',
        'brand',
        'manufacturer',
        'feed_form',
        'purchase_uom_id',
        'stock_uom_id',
        'consumption_uom_id',
        'uom_conversion',
        'default_supplier_id',
        'purchase_price',
        'batch_tracking',
        'expiry_tracking',
        'minimum_stock_level',
        'maximum_stock_level',
        'reorder_level',
        'reorder_quantity',
        'inventory_account',
        'feed_expense_account',
        'status',
        'version',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Modules\Inventory\Models\Item::class, 'itemable');
    }
    public function purchaseUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function stockUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function consumptionUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'consumption_uom_id');
    }

    public function defaultSupplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['uom_conversion' => 'decimal:6', 'purchase_price' => 'decimal:2', 'batch_tracking' => 'boolean', 'expiry_tracking' => 'boolean', 'minimum_stock_level' => 'decimal:3', 'maximum_stock_level' => 'decimal:3', 'reorder_level' => 'decimal:3', 'reorder_quantity' => 'decimal:3'];
    }
}