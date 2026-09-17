<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'guard_name',
        'branch_id',
        'status',
        'version',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logOnly(['name', 'guard_name', 'branch_id', 'status', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
