<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAdvanceLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'staff_id', 'branch_id', 'staff_advance_id', 'staff_advance_repayment_id', 'cashbook_transaction_id',
        'reversal_of_entry_id', 'reference', 'business_date', 'entry_type', 'effect', 'amount',
        'running_balance', 'currency_code', 'description', 'created_by_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'running_balance' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id')->withTrashed();
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(StaffAdvanceRepayment::class, 'staff_advance_repayment_id')->withTrashed();
    }

    public function cashbookTransaction(): BelongsTo
    {
        return $this->belongsTo(CashbookTransaction::class, 'cashbook_transaction_id')->withTrashed();
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
