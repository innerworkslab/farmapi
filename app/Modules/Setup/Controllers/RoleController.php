<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Role;
use App\Modules\Setup\Requests\RoleRequest;
use App\Modules\Setup\Resources\RoleResource;
use App\Modules\Setup\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection($this->roles->paginate($request->query()));
    }

    public function store(RoleRequest $request): RoleResource
    {
        return new RoleResource($this->roles->create($request->validated()));
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load(['permissions', 'branch']));
    }

    public function update(RoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->roles->update($role, $request->validated()));
    }


    public function toggleStatus(Role $role): RoleResource
    {
        return new RoleResource($this->roles->toggleStatus($role));
    }
    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return response()->json(['message' => 'Role deleted successfully.']);
    }
}
