<?php

namespace App\Modules\Setup\Repositories;

use App\Models\User;
use App\Modules\Setup\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StaffRepository
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Staff::query()->with('branch');
        $this->scopeToActorBranches($query, $actor);

        return $query
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('branch_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['employment_status'] ?? null, fn (Builder $q, string $status) => $q->where('employment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $search = trim($search);
                $q->where(function (Builder $match) use ($search): void {
                    $match->where('staff_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function find(int $id): Staff
    {
        return Staff::query()->with('branch')->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Staff
    {
        return Staff::query()->create($data)->load('branch');
    }

    /** @param array<string, mixed> $data */
    public function update(Staff $staff, array $data): Staff
    {
        $staff->fill($data);
        $staff->version++;
        $staff->save();

        return $staff->refresh()->load('branch');
    }

    public function scopeToActorBranches(Builder $query, User $actor): void
    {
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
    }
}
