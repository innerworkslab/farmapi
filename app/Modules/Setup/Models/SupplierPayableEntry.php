<?php

namespace App\Modules\Setup\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayableEntry extends Model
{
    protected $fillable = ['supplier_id', 'branch_id', 'entry_type', 'amount', 'currency_code', 'business_date', 'reference', 'idempotency_key', 'created_by_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'business_date' => 'date'];
    }
}
