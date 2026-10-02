<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Depreciation extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id', 'reference', 'idempotency_key', 'asset_name', 'asset_category_id', 'asset_price',
        'monthly_amount', 'total_posted_amount', 'currency_code', 'start_date', 'next_posting_date',
        'cashbook_id', 'category_id', 'description', 'status', 'created_by_id', 'activated_by_id',
        'activated_at', 'cancelled_by_id', 'cancelled_at', 'cancellation_reason', 'version',
    ];

    protected function casts(): array
    {
        return [
            'asset_price' => 'decimal:2',
            'monthly_amount' => 'decimal:2',
            'total_posted_amount' => 'decimal:2',
            'start_date' => 'date',
            'next_posting_date' => 'date',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function cashbook(): BelongsTo
    {
        return $this->belongsTo(Cashbook::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashLedgerCategory::class)->withTrashed();
    }

    public function assetCategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id')->withTrashed();
    }

    public function scheduleLines(): HasMany
    {
        return $this->hasMany(DepreciationScheduleLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['branch_id', 'reference', 'asset_name', 'asset_category_id', 'asset_price', 'monthly_amount', 'total_posted_amount', 'start_date', 'next_posting_date', 'cashbook_id', 'category_id', 'description', 'status', 'activated_by_id', 'activated_at', 'cancelled_by_id', 'cancelled_at', 'cancellation_reason', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
