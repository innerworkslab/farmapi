<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Requests\SupplierPayableAdjustmentRequest;
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
        return new BusinessMasterResource($this->service->createWithOpeningBalance($request->validated(), $request->user()));
    }

    public function show(Supplier $supplier): BusinessMasterResource
    {
        return new BusinessMasterResource($supplier->load(['preferredBranch', 'types']));
    }

    public function payables(Request $request, Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $this->service->payableSummary($supplier, $request->user())]);
    }

    public function adjustPayable(SupplierPayableAdjustmentRequest $request, Supplier $supplier): JsonResponse
    {
        $entry = $this->service->adjustPayable($supplier, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $entry->id, 'supplier_id' => $entry->supplier_id, 'branch_id' => $entry->branch_id, 'entry_type' => $entry->entry_type, 'amount' => $entry->amount, 'currency_code' => $entry->currency_code, 'business_date' => $entry->business_date->toDateString(), 'reference' => $entry->reference]], 201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->updateWithTypes($supplier, $request->validated(), $request->user()));
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
