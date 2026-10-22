<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Requests\AnimalIndexRequest;
use App\Modules\Setup\Requests\AnimalRequest;
use App\Modules\Setup\Resources\AnimalResource;
use App\Modules\Setup\Services\AnimalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnimalController extends Controller
{
    public function __construct(private readonly AnimalService $service) {}

    public function index(AnimalIndexRequest $request): AnonymousResourceCollection
    {
        return AnimalResource::collection($this->service->paginate($request->validated()));
    }

    public function store(AnimalRequest $request): AnimalResource
    {
        return new AnimalResource($this->service->create($request->validated()));
    }

    public function show(Animal $animal): AnimalResource
    {
        return new AnimalResource($animal);
    }

    public function update(AnimalRequest $request, Animal $animal): AnimalResource
    {
        return new AnimalResource($this->service->update($animal, $request->validated()));
    }

    public function destroy(Animal $animal): JsonResponse
    {
        $this->service->delete($animal);

        return response()->json(['message' => 'Animal deleted successfully.']);
    }
}
