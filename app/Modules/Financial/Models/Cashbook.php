<?php

namespace App\Modules\Financial\Models;

use App\Modules\Setup\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cashbook extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'branch_id', 'type', 'name', 'normalized_name', 'bank_reference', 'currency_code',
        'opening_balance', 'effective_date', 'status', 'deactivation_reason', 'created_by_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'opening_balance' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashbookTransaction::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CashbookLedgerEntry::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['branch_id', 'type', 'name', 'currency_code', 'opening_balance', 'effective_date', 'status', 'deactivation_reason', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
