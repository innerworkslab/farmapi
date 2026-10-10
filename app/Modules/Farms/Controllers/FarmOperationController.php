<?php

namespace App\Modules\Farms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Farms\Requests\FarmActionRequest;
use App\Modules\Farms\Requests\FarmOperationRequest;
use App\Modules\Farms\Resources\FarmOperationResource;
use App\Modules\Farms\Services\FarmOperationService;
use Illuminate\Http\Request;

class FarmOperationController extends Controller
{
    public function __construct(private FarmOperationService $operations) {}

    public function index(Request $request)
    {
        return FarmOperationResource::collection($this->operations->paginate($request->query(), $request->user()));
    }

    public function store(FarmOperationRequest $request)
    {
        return new FarmOperationResource($this->operations->create($request->validated(), $request->user()));
    }

    public function show(Request $request, FarmOperation $operation)
    {
        return new FarmOperationResource($this->operations->find($operation, $request->user()));
    }

    public function update(FarmOperationRequest $request, FarmOperation $operation)
    {
        return new FarmOperationResource($this->operations->update($operation, $request->validated(), $request->user()));
    }

    public function submit(Request $request, FarmOperation $operation)
    {
        return new FarmOperationResource($this->operations->transition($operation, 'submit', $request->user()));
    }

    public function confirm(Request $request, FarmOperation $operation)
    {
        return new FarmOperationResource($this->operations->transition($operation, 'confirm', $request->user()));
    }

    public function reject(FarmActionRequest $request, FarmOperation $operation)
    {
        $data = $request->validated();

        return new FarmOperationResource($this->operations->transition($operation, 'reject', $request->user(), $data['reason']));
    }

    public function completeFollowUp(FarmActionRequest $request, FarmOperation $operation)
    {
        $data = $request->validated();

        return new FarmOperationResource($this->operations->completeFollowUp($operation, $data['notes'], $request->user()));
    }

    public function reverse(FarmActionRequest $request, FarmOperation $operation)
    {
        $data = $request->validated();

        return new FarmOperationResource($this->operations->reverse($operation, $data['reason'], $request->user()));
    }
}
