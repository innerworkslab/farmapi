<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReceivableEntry extends Model
{
    protected $fillable = ['customer_id', 'sale_id', 'branch_id', 'cashbook_transaction_id', 'entry_type', 'amount', 'reference', 'idempotency_key', 'created_by_id'];
    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
}
