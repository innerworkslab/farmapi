<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinancialPostingLimit extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'financial_posting_limits';

    protected $fillable = ['branch_id', 'user_id', 'currency_code', 'maximum_amount', 'allow_self_approval'];

    protected function casts(): array
    {
        return ['maximum_amount' => 'decimal:2', 'allow_self_approval' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
