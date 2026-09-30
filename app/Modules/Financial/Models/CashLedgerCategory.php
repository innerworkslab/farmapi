<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CashLedgerCategory extends Model
{
    use LogsActivity, SoftDeletes;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['name', 'normalized_name', 'direction', 'reversal_category_id', 'status', 'created_by_id'];

    public function reversalCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_category_id');
    }

    public function reversedCategories(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashbookTransaction::class, 'category_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CashbookLedgerEntry::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['name', 'normalized_name', 'direction', 'reversal_category_id', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
