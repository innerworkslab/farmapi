<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CashbookTransfer extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['branch_id', 'source_cashbook_id', 'destination_cashbook_id', 'out_category_id', 'in_category_id', 'out_transaction_id', 'in_transaction_id', 'reference', 'idempotency_key', 'currency_code', 'business_date', 'amount', 'state', 'description', 'reversal_reason', 'created_by_id', 'confirmed_by_id', 'confirmed_at'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'confirmed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
