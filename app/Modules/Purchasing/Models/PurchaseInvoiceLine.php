<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id', 'line_number', 'category', 'item_id', 'description', 'purchase_uom_id',
        'stock_uom_id', 'conversion_factor', 'quantity', 'unit_price', 'gross_amount', 'discount_type',
        'discount_value', 'discount_amount', 'foc_type', 'foc_value', 'foc_quantity', 'tax_rate',
        'tax_amount', 'total_amount', 'total_expected_quantity', 'received_quantity', 'received_foc_quantity',
        'target_inventory_id', 'target_farm_information_id', 'target_location', 'metadata',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}