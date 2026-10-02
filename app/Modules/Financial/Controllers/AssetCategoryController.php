<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\AssetCategory;
use App\Modules\Financial\Requests\AssetCategoryIndexRequest;
use App\Modules\Financial\Requests\AssetCategoryRequest;
use App\Modules\Financial\Requests\AssetCategoryStatusRequest;
use App\Modules\Financial\Resources\AssetCategoryResource;
use App\Modules\Financial\Services\AssetCategoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetCategoryController extends Controller
{
    public function __construct(private readonly AssetCategoryService $categories) {}

    public function index(AssetCategoryIndexRequest $request): AnonymousResourceCollection
    {
        return AssetCategoryResource::collection($this->categories->paginate($request->validated()));
    }

    public function store(AssetCategoryRequest $request): AssetCategoryResource
    {
        return new AssetCategoryResource($this->categories->create($request->validated(), (int) $request->user()->id));
    }

    public function show(AssetCategory $assetCategory): AssetCategoryResource
    {
        return new AssetCategoryResource($assetCategory);
    }

    public function update(AssetCategoryRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        return new AssetCategoryResource($this->categories->update($assetCategory, $request->validated()));
    }

    public function setStatus(AssetCategoryStatusRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        return new AssetCategoryResource($this->categories->setStatus($assetCategory, $request->validated('status')));
    }
}
