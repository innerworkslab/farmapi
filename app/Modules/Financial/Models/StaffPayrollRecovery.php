<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StaffPayrollRecovery extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['staff_advance_id', 'branch_id', 'reference', 'idempotency_key', 'payroll_reference', 'evidence_reference', 'verification_note', 'business_date', 'amount', 'currency_code', 'state', 'description', 'reversal_reason', 'created_by_id', 'verified_by_id', 'verified_at'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
