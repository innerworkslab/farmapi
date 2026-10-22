<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AnimalType extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'normalized_name', 'status', 'version'];

    protected $hidden = ['normalized_name', 'deleted_at'];

    public function categories(): HasMany
    {
        return $this->hasMany(AnimalCategory::class);
    }

    public function breeds(): HasMany
    {
        return $this->hasMany(AnimalBreed::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
