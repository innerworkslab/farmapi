<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\CashLedgerCategory;
use App\Modules\Financial\Requests\CashLedgerCategoryRequest;
use App\Modules\Financial\Requests\CashLedgerCategoryIndexRequest;
use App\Modules\Financial\Requests\CashLedgerCategoryStatusRequest;
use App\Modules\Financial\Resources\CashLedgerCategoryResource;
use App\Modules\Financial\Services\CashLedgerCategoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashLedgerCategoryController extends Controller
{
    public function __construct(private readonly CashLedgerCategoryService $categories) {}

    public function index(CashLedgerCategoryIndexRequest $request): AnonymousResourceCollection
    {
        return CashLedgerCategoryResource::collection($this->categories->paginate($request->query()));
    }

    public function store(CashLedgerCategoryRequest $request): CashLedgerCategoryResource
    {
        return new CashLedgerCategoryResource($this->categories->create($request->validated(), $request->user()->id));
    }

    public function show(CashLedgerCategory $category): CashLedgerCategoryResource
    {
        return new CashLedgerCategoryResource($category->load('reversalCategory'));
    }

    public function update(CashLedgerCategoryRequest $request, CashLedgerCategory $category): CashLedgerCategoryResource
    {
        return new CashLedgerCategoryResource($this->categories->update($category, $request->validated()));
    }

    public function setStatus(CashLedgerCategoryStatusRequest $request, CashLedgerCategory $category): CashLedgerCategoryResource
    {
        return new CashLedgerCategoryResource($this->categories->setStatus($category, $request->validated('status')));
    }
}
