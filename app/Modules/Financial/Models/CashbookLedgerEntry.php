<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashbookLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cashbook_id', 'cashbook_transaction_id', 'category_id', 'reversal_of_entry_id', 'reference', 'entry_date',
        'description', 'source_type', 'direction', 'amount', 'running_balance', 'created_by_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function cashbook(): BelongsTo
    {
        return $this->belongsTo(Cashbook::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CashbookTransaction::class, 'cashbook_transaction_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashLedgerCategory::class, 'category_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
