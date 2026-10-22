<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Division extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'status'];

    public function townships(): HasMany
    {
        return $this->hasMany(Township::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
