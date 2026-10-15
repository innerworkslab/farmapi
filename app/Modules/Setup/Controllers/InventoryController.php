<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Requests\InventoryRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\InventoryService;
use App\Support\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query(), $request->user()));
    }

    public function locationOptions(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->service->locationOptions($request->query(), $request->user())]);
    }

    public function store(InventoryRequest $request): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), (int) $request->validated('branch_id'));

        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Request $request, Inventory $inventory): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), $inventory->branch_id);

        return new BusinessMasterResource($inventory);
    }

    public function update(InventoryRequest $request, Inventory $inventory): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), $inventory->branch_id);
        BranchAccess::authorize($request->user(), (int) $request->validated('branch_id'));

        return new BusinessMasterResource($this->service->update($inventory, $request->validated()));
    }
    public function toggleStatus(Request $request, Inventory $inventory): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), $inventory->branch_id);

        return new BusinessMasterResource($this->service->toggleStatus($inventory));
    }

    public function destroy(Request $request, Inventory $inventory): JsonResponse
    {
        BranchAccess::authorize($request->user(), $inventory->branch_id);

        $this->service->delete($inventory);

        return response()->json(['message' => 'Inventory deleted successfully.']);
    }
}
