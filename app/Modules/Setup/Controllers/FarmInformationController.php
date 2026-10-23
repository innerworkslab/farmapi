<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Requests\FarmInformationRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\FarmInformationService;
use App\Support\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FarmInformationController extends Controller
{
    public function __construct(private readonly FarmInformationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->validate(['search' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'house_barn_id' => ['nullable', 'integer', 'min:1'], 'pen_cage_pond_id' => ['nullable', 'integer', 'min:1'], 'current_animal_id' => ['nullable', 'integer'], 'responsible_employee_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'between:1,100'], 'house_barn' => ['prohibited'], 'pen_cage_pond' => ['prohibited']]), $request->user()));
    }

    public function store(FarmInformationRequest $request): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), (int) $request->validated('branch_id'));

        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Request $request, FarmInformation $farmInformation): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), $farmInformation->branch_id);

        return new BusinessMasterResource($farmInformation);
    }

    public function update(FarmInformationRequest $request, FarmInformation $farmInformation): BusinessMasterResource
    {
        BranchAccess::authorize($request->user(), $farmInformation->branch_id);
        BranchAccess::authorize($request->user(), (int) $request->validated('branch_id'));

        return new BusinessMasterResource($this->service->update($farmInformation, $request->validated()));
    }

    public function destroy(Request $request, FarmInformation $farmInformation): JsonResponse
    {
        BranchAccess::authorize($request->user(), $farmInformation->branch_id);

        $this->service->delete($farmInformation);

        return response()->json(['message' => 'Farm information deleted successfully.']);
    }
}
