<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Farms\Models\FeedingRecord;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryConfirmationService
{
    public function find(InventoryConfirmation $confirmation, User $actor): InventoryConfirmation
    {
        return $this->visibleQuery($actor)->with('ledgerEntries')->findOrFail($confirmation->id);
    }

    private function visibleQuery(User $actor): Builder
    {
        $query = InventoryConfirmation::query();
        if ($actor->hasRole('super-admin')) {
            return $query;
        }

        return $query->where(function (Builder $sources) use ($actor): void {
            $sources->where(function (Builder $adjustments) use ($actor): void {
                $adjustments->where('source_module', 'inventory')->where('source_type', 'adjustment')
                    ->whereIn('source_id', BranchAccess::scope(InventoryAdjustment::query(), $actor)->select('id'));
            })->orWhere(function (Builder $feedings) use ($actor): void {
                $feedings->where('source_module', 'farms')->where('source_type', 'feeding')
                    ->whereIn('source_id', BranchAccess::scope(FeedingRecord::query(), $actor)->select('id'));
            });
        });
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        return $this->visibleQuery($actor)
            ->with('ledgerEntries')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType) => $query->where('source_type', $sourceType))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }
}
