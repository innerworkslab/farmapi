<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CashbookTransaction extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'cashbook_id', 'category_id', 'reference', 'external_reference', 'idempotency_key', 'business_date', 'direction',
        'amount', 'description', 'source_type', 'status', 'created_by_id', 'confirmed_by_id', 'confirmed_at',
        'reverses_transaction_id', 'reversed_by_id', 'reversed_at', 'reversal_reason', 'version',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function cashbook(): BelongsTo
    {
        return $this->belongsTo(Cashbook::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashLedgerCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(CashbookLedgerEntry::class);
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversedByTransaction(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['cashbook_id', 'category_id', 'reference', 'external_reference', 'business_date', 'direction', 'amount', 'description', 'status', 'confirmed_by_id', 'confirmed_at', 'reverses_transaction_id', 'reversed_by_id', 'reversed_at', 'reversal_reason', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
