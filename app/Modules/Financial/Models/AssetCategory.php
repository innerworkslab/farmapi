<?php

namespace App\Modules\Financial\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AssetCategory extends Model
{
    use LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['name', 'normalized_name', 'status', 'created_by_id'];

    public function depreciations(): HasMany
    {
        return $this->hasMany(Depreciation::class, 'asset_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('financial')
            ->logOnly(['name', 'normalized_name', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
