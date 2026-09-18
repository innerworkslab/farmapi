<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryConfirmation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'confirmation_number',
        'source_module',
        'source_type',
        'source_id',
        'submission_version',
        'status',
        'submitted_by_id',
        'submitted_at',
        'confirmed_by_id',
        'confirmed_at',
        'rejected_by_id',
        'rejected_at',
        'rejection_reason',
        'posting_batch_id',
        'idempotency_key',
        'payload_snapshot',
        'validation_snapshot',
    ];

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedgerEntry::class, 'confirmation_id');
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'payload_snapshot' => 'array',
            'validation_snapshot' => 'array',
        ];
    }
}
