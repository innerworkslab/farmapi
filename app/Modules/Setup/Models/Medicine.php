<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Medicine extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'medicines';

    protected $fillable = [
        'code',
        'name',
        'generic_name',
        'type',
        'category',
        'target_animal_type',
        'target_disease',
        'active_ingredient',
        'strength',
        'dosage_form',
        'dosage_unit',
        'recommended_dosage',
        'dosage_frequency',
        'manufacturer',
        'default_supplier_id',
        'registration_number',
        'package_size',
        'purchase_uom_id',
        'stock_uom_id',
        'usage_uom_id',
        'uom_conversion',
        'barcode_sku',
        'batch_tracking',
        'expiry_tracking',
        'cold_chain_required',
        'storage_temperature',
        'storage_instruction',
        'purchase_price',
        'standard_cost',
        'minimum_stock_level',
        'maximum_stock_level',
        'reorder_level',
        'reorder_quantity',
        'tax_type',
        'inventory_account',
        'medicine_expense_account',
        'status',
        'version',
    ];

    public function purchaseUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function stockUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function usageUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Uom::class, 'usage_uom_id');
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
        return ['recommended_dosage' => 'decimal:6', 'uom_conversion' => 'decimal:6', 'batch_tracking' => 'boolean', 'expiry_tracking' => 'boolean', 'cold_chain_required' => 'boolean', 'purchase_price' => 'decimal:2', 'standard_cost' => 'decimal:2', 'minimum_stock_level' => 'decimal:3', 'maximum_stock_level' => 'decimal:3', 'reorder_level' => 'decimal:3', 'reorder_quantity' => 'decimal:3'];
    }
}