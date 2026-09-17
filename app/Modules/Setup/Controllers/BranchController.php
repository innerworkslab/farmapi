<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Requests\BranchRequest;
use App\Modules\Setup\Resources\BranchResource;
use App\Modules\Setup\Services\BranchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BranchResource::collection($this->branches->paginate($request->query()));
    }

    public function store(BranchRequest $request): BranchResource
    {
        return new BranchResource($this->branches->create($request->validated()));
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, $request->validated()));
    }


    public function toggleStatus(Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->toggleStatus($branch));
    }
    public function destroy(Branch $branch): JsonResponse
    {
        $this->branches->delete($branch);

        return response()->json(['message' => 'Branch deleted successfully.']);
    }
}
