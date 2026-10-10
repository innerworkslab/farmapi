<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CashEntryClassification extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['cashbook_ledger_entry_id', 'report_treatment', 'reason', 'created_by_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Cash classifications are immutable.'));
        static::deleting(fn () => throw new \LogicException('Cash classifications cannot be deleted.'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('financial')->logFillable()->dontSubmitEmptyLogs();
    }
}
