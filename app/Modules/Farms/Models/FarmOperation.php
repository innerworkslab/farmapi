<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FarmOperation extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_operations';

    protected $fillable = ['reference', 'type', 'branch_id', 'farm_information_id', 'activity_date', 'activity_time', 'workflow_state', 'idempotency_key', 'payload', 'created_by_id', 'confirmed_by_id', 'confirmed_at', 'original_operation_id', 'version'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'activity_date' => 'date', 'confirmed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function targets(): HasMany
    {
        return $this->hasMany(OperationTarget::class, 'operation_id');
    }
}
