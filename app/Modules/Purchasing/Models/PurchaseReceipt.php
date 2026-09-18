<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseReceipt extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'receipt_number', 'purchase_invoice_id', 'receipt_date', 'status', 'idempotency_key', 'posting_batch_id',
        'notes', 'created_by_id', 'confirmed_by_id', 'confirmed_at', 'version',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class)->orderBy('line_number');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('purchasing')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'confirmed_at' => 'datetime'];
    }
}