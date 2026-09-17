<?php

namespace App\Modules\Setup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Branch extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'code',
        'name',
        'phone_number',
        'address',
        'status',
        'version',
    ];

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logOnly([
                'code',
                'name',
                'phone_number',
                'address',
                'status',
                'version',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
