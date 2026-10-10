<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeedingTarget extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_feeding_targets';

    protected $fillable = ['feeding_id', 'animal_balance_id'];

    protected function casts(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
