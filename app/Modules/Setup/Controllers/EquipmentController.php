<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Requests\EquipmentRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\EquipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EquipmentController extends Controller
{
    public function __construct(private readonly EquipmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(EquipmentRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Equipment $equipment): BusinessMasterResource
    {
        return new BusinessMasterResource($equipment);
    }

    public function update(EquipmentRequest $request, Equipment $equipment): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($equipment, $request->validated()));
    }

    public function destroy(Equipment $equipment): JsonResponse
    {
        $this->service->delete($equipment);

        return response()->json(['message' => 'Equipment deleted successfully.']);
    }
}