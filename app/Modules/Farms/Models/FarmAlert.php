<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FarmAlert extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_alerts';

    protected $fillable = ['farm_information_id', 'animal_balance_id', 'type', 'alert_date', 'alert_key', 'details', 'investigation', 'investigated_by_id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'alert_date' => 'date'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
