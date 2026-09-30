<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StaffAdvance extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'staff_id', 'branch_id', 'reference', 'idempotency_key', 'principal_amount', 'currency_code', 'business_date',
        'cashbook_id', 'category_id', 'description', 'staff_code_snapshot', 'staff_name_snapshot',
        'branch_code_snapshot', 'branch_name_snapshot', 'cashbook_transaction_id', 'status',
        'created_by_id', 'confirmed_by_id', 'confirmed_at', 'reversed_by_id', 'reversed_at',
        'reversal_reason', 'version',
    ];

    protected function casts(): array
    {
        return ['principal_amount' => 'decimal:2', 'business_date' => 'date', 'confirmed_at' => 'datetime', 'reversed_at' => 'datetime'];
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

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffAdvanceRepayment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StaffAdvanceLedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['staff_id', 'branch_id', 'reference', 'principal_amount', 'business_date', 'cashbook_id', 'category_id', 'description', 'status', 'confirmed_by_id', 'confirmed_at', 'reversed_by_id', 'reversed_at', 'reversal_reason', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
