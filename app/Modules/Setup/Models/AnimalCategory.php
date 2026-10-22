<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AnimalCategory extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['animal_type_id', 'name', 'normalized_name', 'status', 'version'];

    protected $hidden = ['normalized_name', 'deleted_at'];

    public function animalType(): BelongsTo
    {
        return $this->belongsTo(AnimalType::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
