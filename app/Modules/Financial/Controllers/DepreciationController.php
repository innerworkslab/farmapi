<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\Depreciation;
use App\Modules\Financial\Models\DepreciationScheduleLine;
use App\Modules\Financial\Requests\DepreciationIndexRequest;
use App\Modules\Financial\Requests\DepreciationReasonRequest;
use App\Modules\Financial\Requests\DepreciationRequest;
use App\Modules\Financial\Resources\DepreciationResource;
use App\Modules\Financial\Resources\DepreciationScheduleLineResource;
use App\Modules\Financial\Services\DepreciationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepreciationController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciations) {}

    public function index(DepreciationIndexRequest $request): AnonymousResourceCollection
    {
        return DepreciationResource::collection($this->depreciations->paginate($request->validated(), $request->user()));
    }

    public function store(DepreciationRequest $request): DepreciationResource
    {
        return new DepreciationResource($this->depreciations->createDraft($request->validated(), $request->user()));
    }

    public function show(Depreciation $depreciation, Request $request): DepreciationResource
    {
        return new DepreciationResource($this->depreciations->find($depreciation, $request->user()));
    }

    public function update(DepreciationRequest $request, Depreciation $depreciation): DepreciationResource
    {
        return new DepreciationResource($this->depreciations->updateDraft($depreciation, $request->validated(), $request->user()));
    }

    public function activate(Depreciation $depreciation, Request $request): DepreciationResource
    {
        return new DepreciationResource($this->depreciations->activate($depreciation, $request->user()));
    }

    public function cancel(DepreciationReasonRequest $request, Depreciation $depreciation): DepreciationResource
    {
        return new DepreciationResource($this->depreciations->cancel($depreciation, $request->validated('reason'), $request->user()));
    }

    public function schedule(Depreciation $depreciation, Request $request): AnonymousResourceCollection
    {
        return DepreciationScheduleLineResource::collection(
            $this->depreciations->schedule($depreciation, $request->user(), (int) $request->query('per_page', 30))
        );
    }

    public function postLine(DepreciationScheduleLine $line, Request $request): DepreciationScheduleLineResource
    {
        return new DepreciationScheduleLineResource($this->depreciations->postScheduleLine($line, $request->user()));
    }

    public function reverseLine(DepreciationReasonRequest $request, DepreciationScheduleLine $line): DepreciationScheduleLineResource
    {
        return new DepreciationScheduleLineResource($this->depreciations->reverseScheduleLine($line, $request->validated('reason'), $request->user()));
    }
}
