<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HouseBarn extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'normalized_name', 'status', 'version'];

    protected $hidden = ['normalized_name', 'deleted_at'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function farms(): BelongsToMany
    {
        return $this->belongsToMany(FarmInformation::class, 'farm_information_house_barn')
            ->withPivot('branch_id')->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
