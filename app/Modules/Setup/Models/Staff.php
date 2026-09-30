<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Staff extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const EMPLOYMENT_EMPLOYED = 'employed';

    public const EMPLOYMENT_TERMINATED = 'terminated';

    protected $fillable = [
        'staff_code',
        'normalized_staff_code',
        'name',
        'phone_number',
        'branch_id',
        'employment_status',
        'status',
        'version',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isEligibleForAdvance(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->employment_status === self::EMPLOYMENT_EMPLOYED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logOnly(['staff_code', 'name', 'phone_number', 'branch_id', 'employment_status', 'status', 'version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
