<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryWorkflowEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'eventable_type',
        'eventable_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
