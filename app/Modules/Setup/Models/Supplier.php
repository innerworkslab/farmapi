<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'suppliers';

    protected $fillable = [
        'code',
        'type',
        'name',
        'contact_person',
        'phone_number',
        'alternative_phone',
        'address',
        'township',
        'state_region',
        'country',
        'supplied_categories',
        'preferred_branch_id',
        'lead_time_days',
        'minimum_order_amount',
        'payment_terms',
        'credit_limit',
        'opening_balance_type',
        'opening_balance',
        'opening_balance_date',
        'bank_name',
        'bank_account_name',
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
        return ['supplied_categories' => 'array', 'lead_time_days' => 'integer', 'minimum_order_amount' => 'decimal:2', 'credit_limit' => 'decimal:2', 'opening_balance' => 'decimal:2', 'opening_balance_date' => 'date'];
    }
}