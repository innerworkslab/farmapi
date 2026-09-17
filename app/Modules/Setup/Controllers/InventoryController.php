<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Requests\InventoryRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(InventoryRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Inventory $inventory): BusinessMasterResource
    {
        return new BusinessMasterResource($inventory);
    }

    public function update(InventoryRequest $request, Inventory $inventory): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($inventory, $request->validated()));
    }
    public function toggleStatus(Inventory $inventory): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($inventory));
    }

    public function destroy(Inventory $inventory): JsonResponse
    {
        $this->service->delete($inventory);

        return response()->json(['message' => 'Inventory deleted successfully.']);
    }
}