<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Models\Role;
use App\Modules\Setup\Repositories\RoleRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    public function __construct(private readonly RoleRepository $roles) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->roles->paginate($filters);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $permissions = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = $this->roles->create($data);
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load(['permissions', 'branch']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            $role = $this->roles->update($role, $data);

            if (is_array($permissions)) {
                $role->syncPermissions($permissions);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load(['permissions', 'branch']);
        });
    }


    public function toggleStatus(Role $role): Role
    {
        return DB::transaction(function () use ($role): Role {
            $role = $this->roles->toggleStatus($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load(['permissions', 'branch']);
        });
    }
    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $this->roles->delete($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
