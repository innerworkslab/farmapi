<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Setup\Repositories\AdminRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminService
{
    public function __construct(private readonly AdminRepository $admins) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->admins->paginate($filters);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $roles = $data['roles'] ?? [];
            $branchIds = $data['branch_ids'] ?? [];
            $data = Arr::except($data, ['roles', 'branch_ids']);
            $data['password'] = Hash::make($data['password']);
            $data['password_changed_at'] = now();

            $admin = $this->admins->create($data);
            $admin->syncRoles($roles);
            $admin->branches()->sync($branchIds);

            return $admin->load(['roles', 'branches']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $roles = $data['roles'] ?? null;
            $branchIds = $data['branch_ids'] ?? null;
            $data = Arr::except($data, ['roles', 'branch_ids']);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
                $data['password_changed_at'] = now();
            }

            $admin = $this->admins->update($admin, $data);

            if (is_array($roles)) {
                $admin->syncRoles($roles);
            }

            if (is_array($branchIds)) {
                $admin->branches()->sync($branchIds);
            }

            return $admin->load(['roles', 'branches']);
        });
    }

    public function delete(User $admin): void
    {
        DB::transaction(function () use ($admin): void {
            $admin->syncRoles([]);
            $admin->branches()->sync([]);
            $this->admins->delete($admin);
        });
    }
}
