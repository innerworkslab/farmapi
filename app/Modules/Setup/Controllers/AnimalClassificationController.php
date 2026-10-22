<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Requests\AnimalClassificationRequest;
use App\Modules\Setup\Resources\AnimalClassificationResource;
use App\Modules\Setup\Services\AnimalClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AnimalClassificationController extends Controller
{
    public function __construct(private readonly AnimalClassificationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'animal_type_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return AnimalClassificationResource::collection($this->service->paginate($request->route('classification_kind'), $filters));
    }

    public function store(AnimalClassificationRequest $request): AnimalClassificationResource
    {
        return new AnimalClassificationResource($this->service->save($request->route('classification_kind'), $request->validated()));
    }

    public function show(Request $request, string $classification): AnimalClassificationResource
    {
        return new AnimalClassificationResource($this->service->find($request->route('classification_kind'), (int) $classification));
    }

    public function update(AnimalClassificationRequest $request, string $classification): AnimalClassificationResource
    {
        return new AnimalClassificationResource($this->service->save($request->route('classification_kind'), $request->validated(), (int) $classification));
    }

    public function toggleStatus(Request $request, string $classification): AnimalClassificationResource
    {
        return new AnimalClassificationResource($this->service->toggleStatus($request->route('classification_kind'), (int) $classification));
    }

    public function destroy(Request $request, string $classification): JsonResponse
    {
        $this->service->delete($request->route('classification_kind'), (int) $classification);

        return response()->json(['message' => 'Animal classification deleted successfully.']);
    }
}
