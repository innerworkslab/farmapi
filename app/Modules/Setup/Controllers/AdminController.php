<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Setup\Requests\AdminRequest;
use App\Modules\Setup\Resources\AdminResource;
use App\Modules\Setup\Services\AdminService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function __construct(private readonly AdminService $admins) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AdminResource::collection($this->admins->paginate($request->query()));
    }

    public function store(AdminRequest $request): AdminResource
    {
        return new AdminResource($this->admins->create($request->validated()));
    }

    public function show(User $admin): AdminResource
    {
        return new AdminResource($admin->load(['roles', 'branches']));
    }

    public function update(AdminRequest $request, User $admin): AdminResource
    {
        return new AdminResource($this->admins->update($admin, $request->validated()));
    }

    public function destroy(User $admin): JsonResponse
    {
        $this->admins->delete($admin);

        return response()->json(['message' => 'Admin deleted successfully.']);
    }
}
