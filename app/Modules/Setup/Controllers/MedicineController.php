<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Requests\MedicineRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\MedicineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MedicineController extends Controller
{
    public function __construct(private readonly MedicineService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'animal_type_id' => ['nullable', 'integer', 'min:1'],
            'target_animal_type' => ['prohibited'],
            'category' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'default_supplier_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return BusinessMasterResource::collection($this->service->paginate($filters));
    }

    public function store(MedicineRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Medicine $medicine): BusinessMasterResource
    {
        return new BusinessMasterResource($medicine->load(['purchaseUom', 'stockUom', 'usageUom', 'defaultSupplier']));
    }

    public function update(MedicineRequest $request, Medicine $medicine): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($medicine, $request->validated()));
    }

    public function toggleStatus(Medicine $medicine): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($medicine));
    }

    public function destroy(Medicine $medicine): JsonResponse
    {
        $this->service->delete($medicine);

        return response()->json(['message' => 'Medicine deleted successfully.']);
    }
}
