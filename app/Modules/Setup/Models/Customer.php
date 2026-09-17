<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'code',
        'type',
        'name',
        'contact_person',
        'phone_number',
        'alternative_phone',
        'delivery_address',
        'township',
        'state_region',
        'preferred_branch_id',
        'price_level',
        'payment_terms',
        'credit_limit',
        'opening_balance',
        'opening_balance_date',
        'bank_name',
        'bank_account_number',
        'status',
        'version',
    ];

    public function preferredBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
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
        return ['credit_limit' => 'decimal:2', 'opening_balance' => 'decimal:2', 'opening_balance_date' => 'date'];
    }
}