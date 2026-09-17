<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RoleRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Role::query()
            ->with(['permissions', 'branch'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int|string $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role
    {
        return Role::query()->create($data + ['guard_name' => 'web', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        $role->fill($data);
        $role->version++;
        $role->save();

        return $role->refresh();
    }


    public function toggleStatus(Role $role): Role
    {
        $role->status = $role->status === 'active' ? 'inactive' : 'active';
        $role->version++;
        $role->save();

        return $role->refresh();
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }
}
