<?php

namespace App\Modules\Auth\Repositories;

use Spatie\Permission\Models\Permission;

class PermissionRepository
{
    /**
     * @return list<array{name: string, guard_name: string}>
     */
    public function allOrdered(): array
    {
        return Permission::query()
            ->select(['name', 'guard_name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission): array => [
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ])
            ->values()
            ->all();
    }
}
