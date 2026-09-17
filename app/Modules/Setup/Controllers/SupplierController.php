<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Requests\SupplierRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(SupplierRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Supplier $supplier): BusinessMasterResource
    {
        return new BusinessMasterResource($supplier);
    }

    public function update(SupplierRequest $request, Supplier $supplier): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($supplier, $request->validated()));
    }
    public function toggleStatus(Supplier $supplier): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($supplier));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->service->delete($supplier);

        return response()->json(['message' => 'Supplier deleted successfully.']);
    }
}