<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchasePayment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'purchase_payments';

    protected $fillable = ['purchase_invoice_id', 'branch_id', 'cashbook_id', 'category_id', 'cashbook_transaction_id', 'reference', 'idempotency_key', 'currency_code', 'business_date', 'amount', 'state', 'description', 'reversal_reason', 'created_by_id', 'confirmed_by_id', 'confirmed_at'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'confirmed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
