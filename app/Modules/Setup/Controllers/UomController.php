<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Uom;
use App\Modules\Setup\Requests\UomRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\UomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UomController extends Controller
{
    public function __construct(private readonly UomService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(UomRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Uom $uom): BusinessMasterResource
    {
        return new BusinessMasterResource($uom);
    }

    public function update(UomRequest $request, Uom $uom): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($uom, $request->validated()));
    }
    public function toggleStatus(Uom $uom): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($uom));
    }

    public function destroy(Uom $uom): JsonResponse
    {
        $this->service->delete($uom);

        return response()->json(['message' => 'UOM deleted successfully.']);
    }
}