<?php

namespace App\Modules\Sales\Models;

use App\Modules\Farms\Models\AnimalProfile;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Sale extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['sale_number', 'idempotency_key', 'branch_id', 'farm_information_id', 'inventory_balance_id', 'customer_id', 'sale_date', 'quantity', 'foc_quantity', 'unit_price', 'discount_type', 'discount_value', 'gross_amount', 'discount_amount', 'total_amount', 'paid_amount', 'ar_amount', 'payment_status', 'status', 'customer_snapshot', 'cashbook_id', 'cash_category_id', 'created_by_id', 'confirmed_by_id', 'confirmed_at', 'cancellation_reason', 'cancelled_by_id', 'cancelled_at', 'version'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'unit_price' => 'decimal:2', 'discount_value' => 'decimal:2', 'gross_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'ar_amount' => 'decimal:2', 'customer_snapshot' => 'array', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function farm(): BelongsTo { return $this->belongsTo(FarmInformation::class, 'farm_information_id'); }
    public function balance(): BelongsTo { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
    public function receivableEntries(): HasMany { return $this->hasMany(CustomerReceivableEntry::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('sales')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
