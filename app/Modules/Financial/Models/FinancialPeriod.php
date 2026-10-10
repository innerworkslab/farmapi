<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinancialPeriod extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'financial_periods';

    protected $fillable = ['branch_id', 'name', 'from_date', 'to_date', 'state', 'closed_by_id', 'closed_at', 'reconciliation_snapshot'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'closed_at' => 'datetime', 'reconciliation_snapshot' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
