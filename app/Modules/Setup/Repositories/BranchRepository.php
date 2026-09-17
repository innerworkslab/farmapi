<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BranchRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Branch::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Branch
    {
        return Branch::query()->create($data + ['status' => Branch::STATUS_ACTIVE]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        $branch->fill($data);
        $branch->version++;
        $branch->save();

        return $branch->refresh();
    }

    public function toggleStatus(Branch $branch): Branch
    {
        $branch->status = $branch->status === Branch::STATUS_ACTIVE
            ? Branch::STATUS_INACTIVE
            : Branch::STATUS_ACTIVE;
        $branch->version++;
        $branch->save();

        return $branch->refresh();
    }

    public function delete(Branch $branch): void
    {
        $branch->delete();
    }
}
