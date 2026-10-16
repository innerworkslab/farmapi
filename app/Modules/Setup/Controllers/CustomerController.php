<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Requests\CustomerReceivableAdjustmentRequest;
use App\Modules\Setup\Requests\CustomerRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(CustomerRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->createWithOpeningBalance($request->validated(), $request->user()));
    }

    public function show(Customer $customer): BusinessMasterResource
    {
        return new BusinessMasterResource($customer);
    }

    public function receivables(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->service->receivableRecords($customer, $request->user())]);
    }

    public function adjustReceivable(CustomerReceivableAdjustmentRequest $request, Customer $customer): JsonResponse
    {
        $entry = $this->service->adjustReceivable($customer, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $entry->id, 'customer_id' => $entry->customer_id, 'branch_id' => $entry->branch_id, 'entry_type' => $entry->entry_type, 'amount' => $entry->amount, 'business_date' => $entry->business_date->toDateString(), 'reference' => $entry->reference]], 201);
    }

    public function update(CustomerRequest $request, Customer $customer): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($customer, $request->validated()));
    }

    public function toggleStatus(Customer $customer): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($customer));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->service->delete($customer);

        return response()->json(['message' => 'Customer deleted successfully.']);
    }
}
