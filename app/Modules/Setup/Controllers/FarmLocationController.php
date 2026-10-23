<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Requests\FarmLocationRequest;
use App\Modules\Setup\Resources\FarmLocationResource;
use App\Modules\Setup\Services\FarmLocationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FarmLocationController extends Controller
{
    public function __construct(private readonly FarmLocationService $service) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'], 'house_barn_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return FarmLocationResource::collection($this->service->paginate($request->route('location_kind'), $filters, $request->user()));
    }

    public function show(Request $request, string $location)
    {
        return new FarmLocationResource($this->service->find($request->route('location_kind'), (int) $location, $request->user()));
    }

    public function store(FarmLocationRequest $request)
    {
        return new FarmLocationResource($this->service->save($request->route('location_kind'), $request->validated(), $request->user()));
    }

    public function update(FarmLocationRequest $request, string $location)
    {
        return new FarmLocationResource($this->service->save($request->route('location_kind'), $request->validated(), $request->user(), (int) $location));
    }

    public function toggleStatus(Request $request, string $location)
    {
        return new FarmLocationResource($this->service->toggleStatus($request->route('location_kind'), (int) $location, $request->user()));
    }

    public function destroy(Request $request, string $location)
    {
        $this->service->delete($request->route('location_kind'), (int) $location, $request->user());

        return response()->json(['message' => 'Farm location deleted successfully.']);
    }
}
