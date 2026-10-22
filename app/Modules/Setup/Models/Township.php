<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Township extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['division_id', 'name', 'status'];

    protected function casts(): array
    {
        return ['division_id' => 'integer'];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('setup')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
