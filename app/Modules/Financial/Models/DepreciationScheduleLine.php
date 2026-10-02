<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DepreciationScheduleLine extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'depreciation_id', 'sequence_number', 'due_date', 'amount', 'status', 'cashbook_transaction_id',
        'posted_by_id', 'posted_at', 'failure_reason', 'reversed_by_id', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function depreciation(): BelongsTo
    {
        return $this->belongsTo(Depreciation::class);
    }

    public function cashbookTransaction(): BelongsTo
    {
        return $this->belongsTo(CashbookTransaction::class, 'cashbook_transaction_id')->withTrashed();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['depreciation_id', 'sequence_number', 'due_date', 'amount', 'status', 'cashbook_transaction_id', 'posted_by_id', 'posted_at', 'failure_reason', 'reversed_by_id', 'reversed_at', 'reversal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
