<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_receipt_id', 'purchase_invoice_line_id', 'line_number', 'category', 'item_id',
        'accepted_quantity', 'accepted_foc_quantity', 'rejected_quantity', 'rejection_reason',
        'target_inventory_id', 'target_farm_information_id', 'target_location', 'purchase_uom_id',
        'stock_uom_id', 'conversion_factor', 'supplier_batch_number', 'receipt_lot_number',
        'manufacturing_date', 'expiry_date', 'cold_chain_required', 'cold_chain_status',
        'observed_temperature', 'temperature_uom', 'exception_reason', 'metadata',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'cold_chain_required' => 'boolean',
            'metadata' => 'array',
        ];
    }
}