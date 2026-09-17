<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Requests\AnimalRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\AnimalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnimalController extends Controller
{
    public function __construct(private readonly AnimalService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(AnimalRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Animal $animal): BusinessMasterResource
    {
        return new BusinessMasterResource($animal);
    }

    public function update(AnimalRequest $request, Animal $animal): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($animal, $request->validated()));
    }

    public function destroy(Animal $animal): JsonResponse
    {
        $this->service->delete($animal);

        return response()->json(['message' => 'Animal deleted successfully.']);
    }
}