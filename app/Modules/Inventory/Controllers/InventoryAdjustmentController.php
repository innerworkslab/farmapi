<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Requests\InventoryAdjustmentRequest;
use App\Modules\Inventory\Requests\InventoryRejectRequest;
use App\Modules\Inventory\Requests\InventoryReverseRequest;
use App\Modules\Inventory\Resources\InventoryAdjustmentResource;
use App\Modules\Inventory\Services\InventoryAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryAdjustmentController extends Controller
{
    public function __construct(private readonly InventoryAdjustmentService $adjustments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InventoryAdjustmentResource::collection($this->adjustments->paginate($request->query()));
    }

    public function store(InventoryAdjustmentRequest $request): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource(
            $this->adjustments->create($request->validated(), $request->user())
        );
    }

    public function show(InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource($adjustment->load(['lines', 'confirmation']));
    }

    public function update(InventoryAdjustmentRequest $request, InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource(
            $this->adjustments->update($adjustment, $request->validated(), $request->user())
        );
    }

    public function submit(Request $request, InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource($this->adjustments->submit($adjustment, $request->user()));
    }

    public function confirm(Request $request, InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource($this->adjustments->confirm($adjustment, $request->user()));
    }

    public function reject(InventoryRejectRequest $request, InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource(
            $this->adjustments->reject($adjustment, $request->validated('reason'), $request->user())
        );
    }

    public function reverse(InventoryReverseRequest $request, InventoryAdjustment $adjustment): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource(
            $this->adjustments->reverse($adjustment, $request->validated('reason'), $request->user())
        );
    }
}
