<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryWorkflowEvent;
use Illuminate\Database\Eloquent\Model;

class InventoryWorkflowService
{
    /** @param array<string, mixed>|null $metadata */
    public function record(
        Model $eventable,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor,
        ?string $reason = null,
        ?array $metadata = null,
    ): InventoryWorkflowEvent {
        return InventoryWorkflowEvent::query()->create([
            'eventable_type' => $eventable::class,
            'eventable_id' => $eventable->getKey(),
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
