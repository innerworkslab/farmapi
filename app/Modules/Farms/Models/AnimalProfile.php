<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AnimalProfile extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_animal_profiles';

    protected $fillable = ['animal_balance_id', 'initial_quantity', 'death_quantity', 'affected_quantity', 'health_status', 'animal_state', 'date_of_birth', 'weight', 'weight_uom', 'life_stage', 'withdrawal_end_date', 'bio_data'];

    protected function casts(): array
    {
        return ['bio_data' => 'array', 'date_of_birth' => 'date', 'withdrawal_end_date' => 'date', 'weight' => 'decimal:6'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
