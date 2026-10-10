<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinancialBranchSetting extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'financial_branch_settings';

    protected $fillable = ['branch_id', 'default_currency', 'enabled_currencies', 'timezone', 'require_open_period', 'require_approval_limit'];

    protected function casts(): array
    {
        return ['enabled_currencies' => 'array', 'require_open_period' => 'boolean', 'require_approval_limit' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
