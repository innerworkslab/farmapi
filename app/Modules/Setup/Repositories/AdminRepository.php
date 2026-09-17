<?php

namespace App\Modules\Setup\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AdminRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'branches'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->when($filters['account_status'] ?? null, fn (Builder $query, string $status) => $query->where('account_status', $status))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($filters['branch_id'] ?? null, function (Builder $query, int|string $branchId): void {
                $query->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $branchId));
            })
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $admin, array $data): User
    {
        $admin->fill($data);
        $admin->save();

        return $admin->refresh();
    }

    public function delete(User $admin): void
    {
        $admin->delete();
    }
}
