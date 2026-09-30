<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StaffAdvanceRepayment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'staff_advance_id', 'staff_id', 'branch_id', 'reference', 'idempotency_key', 'amount', 'business_date',
        'cashbook_id', 'category_id', 'external_reference', 'description', 'cashbook_transaction_id', 'status',
        'created_by_id', 'confirmed_by_id', 'confirmed_at', 'cancelled_by_id', 'cancelled_at', 'cancellation_reason',
        'reversed_by_id', 'reversed_at', 'reversal_reason', 'version',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'business_date' => 'date', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
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

    public function cashbookTransaction(): BelongsTo
    {
        return $this->belongsTo(CashbookTransaction::class, 'cashbook_transaction_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['staff_advance_id', 'staff_id', 'branch_id', 'reference', 'amount', 'business_date', 'cashbook_id', 'category_id', 'description', 'status', 'confirmed_by_id', 'confirmed_at', 'cancelled_by_id', 'cancelled_at', 'cancellation_reason', 'reversed_by_id', 'reversed_at', 'reversal_reason', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
