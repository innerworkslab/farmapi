<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Setup\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseInvoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_CANCELLED = 'cancelled';
    public const DELIVERY_NOT_RECEIVED = 'not_received';
    public const DELIVERY_PARTIALLY_RECEIVED = 'partially_received';
    public const DELIVERY_FULLY_RECEIVED = 'fully_received';
    public const DELIVERY_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_number', 'invoice_date', 'supplier_id', 'branch_id', 'status', 'delivery_status',
        'gross_amount', 'discount_amount', 'tax_amount', 'total_amount', 'total_expected_quantity',
        'total_received_quantity', 'notes', 'cancellation_reason', 'created_by_id', 'cancelled_by_id',
        'cancelled_at', 'version',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('line_number');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('purchasing')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'cancelled_at' => 'datetime'];
    }
}