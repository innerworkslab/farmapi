<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Requests\FoodRequest;
use App\Modules\Setup\Resources\BusinessMasterResource;
use App\Modules\Setup\Services\FoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FoodController extends Controller
{
    public function __construct(private readonly FoodService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BusinessMasterResource::collection($this->service->paginate($request->query()));
    }

    public function store(FoodRequest $request): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->create($request->validated()));
    }

    public function show(Food $food): BusinessMasterResource
    {
        return new BusinessMasterResource($food);
    }

    public function update(FoodRequest $request, Food $food): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->update($food, $request->validated()));
    }
    public function toggleStatus(Food $food): BusinessMasterResource
    {
        return new BusinessMasterResource($this->service->toggleStatus($food));
    }

    public function destroy(Food $food): JsonResponse
    {
        $this->service->delete($food);

        return response()->json(['message' => 'Food deleted successfully.']);
    }
}