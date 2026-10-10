<?php

namespace App\Modules\Farms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeedingPlan extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'farm_feeding_plans';

    protected $fillable = ['farm_information_id', 'plan_date', 'animal_balance_id', 'food_item_id', 'stock_uom_id', 'quantity', 'created_by_id'];

    protected function casts(): array
    {
        return ['plan_date' => 'date', 'quantity' => 'decimal:6'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('farms')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
