<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FarmSetting extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_settings';

    protected $fillable = ['farm_information_id', 'status', 'timezone', 'daily_mortality_threshold', 'cumulative_mortality_threshold', 'feed_variance_threshold', 'feed_wastage_threshold', 'sickness_threshold', 'expiry_warning_days', 'veterinarian_id'];

    protected function casts(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
