<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Animal extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'animals';

    protected $fillable = [
        'code',
        'tracking_type',
        'ear_tag_rfid_number',
        'batch_flock_number',
        'name',
        'type',
        'category',
        'breed',
        'gender',
        'color_marking',
        'photo_path',
        'version',
    ];



    public function item(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Modules\Inventory\Models\Item::class, 'itemable');
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [];
    }
}