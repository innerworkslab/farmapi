<?php

namespace App\Modules\Setup\Models;

use App\Models\User;
use App\Modules\Farms\Models\FarmSetting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FarmInformation extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'farm_information';

    protected $with = ['houseBarn', 'penCagePond'];

    protected $fillable = [
        'name',
        'branch_id',
        'house_barn_id',
        'pen_cage_pond_id',
        'current_animal_id',
        'responsible_employee_id',
        'version',
    ];

    public function setting(): HasOne
    {
        return $this->hasOne(FarmSetting::class, 'farm_information_id');
    }

    public function houseBarn(): BelongsTo
    {
        return $this->belongsTo(HouseBarn::class);
    }

    public function penCagePond(): BelongsTo
    {
        return $this->belongsTo(PenCagePond::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currentAnimal(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'current_animal_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_employee_id');
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
