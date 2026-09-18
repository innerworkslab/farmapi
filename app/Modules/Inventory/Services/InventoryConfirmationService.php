<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryConfirmation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryConfirmationService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return InventoryConfirmation::query()
            ->with('ledgerEntries')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType) => $query->where('source_type', $sourceType))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }
}
