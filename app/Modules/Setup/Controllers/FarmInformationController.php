<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Requests\FarmInformationRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\FarmInformationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FarmInformationController extends Controller
{
    public function __construct(private readonly FarmInformationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(FarmInformationRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(FarmInformation $farmInformation): BusinessMasterResource
    {
        return new BusinessMasterResource($farmInformation);
    }

    public function update(FarmInformationRequest $request, FarmInformation $farmInformation): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($farmInformation, $request->validated()));
    }

    public function destroy(FarmInformation $farmInformation): JsonResponse
    {
        $this->service->delete($farmInformation);

        return response()->json(['message' => 'Farm information deleted successfully.']);
    }
}