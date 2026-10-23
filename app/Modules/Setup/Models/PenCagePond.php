<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PenCagePond extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['branch_id', 'house_barn_id', 'name', 'normalized_name', 'status', 'version'];

    protected $hidden = ['normalized_name', 'deleted_at'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function houseBarn(): BelongsTo
    {
        return $this->belongsTo(HouseBarn::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
