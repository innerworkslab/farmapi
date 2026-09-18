<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Setup\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InventoryAdjustment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'adjustment_number',
        'type',
        'adjustment_date',
        'branch_id',
        'inventory_id',
        'farm_information_id',
        'status',
        'reason_type',
        'reason',
        'notes',
        'attachment_path',
        'created_by_id',
        'submitted_by_id',
        'submitted_at',
        'confirmed_by_id',
        'confirmed_at',
        'rejected_by_id',
        'rejected_at',
        'rejection_reason',
        'posting_batch_id',
        'original_adjustment_id',
        'version',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class, 'adjustment_id')->orderBy('line_number');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(InventoryConfirmation::class, 'source_id')
            ->where('source_type', 'adjustment');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
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
            'adjustment_date' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
