<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OperationTarget extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_operation_targets';

    protected $fillable = ['operation_id', 'animal_balance_id', 'quantity', 'snapshot'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(FarmOperation::class, 'operation_id');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
