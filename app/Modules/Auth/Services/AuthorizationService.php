<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Repositories\PermissionRepository;

class AuthorizationService
{
    public function __construct(private readonly PermissionRepository $permissions) {}

    /**
     * @return list<array{name: string, guard_name: string}>
     */
    public function permissionCatalog(): array
    {
        return $this->permissions->allOrdered();
    }
}
