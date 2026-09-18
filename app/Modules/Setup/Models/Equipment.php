<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Equipment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'equipment';

    protected $fillable = [
        'code',
        'name',
        'category',
        'brand',
        'model',
        'serial_number',
        'manufacturer',
        'supplier_id',
        'purchase_cost',
        'attachment_path',
        'version',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Modules\Inventory\Models\Item::class, 'itemable');
    }
    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
        return ['purchase_cost' => 'decimal:2'];
    }
}