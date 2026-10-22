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
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'building_zone' => ['prohibited'],
            'rack_bin' => ['prohibited'],
        ]);

        return BusinessMasterResource::collection($this->service->paginate($filters, $request->user()));
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
