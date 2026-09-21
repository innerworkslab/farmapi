<?php

namespace App\Modules\Farms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Farms\Models\FeedingRecord;
use App\Modules\Farms\Requests\FeedingRecordRequest;
use App\Modules\Farms\Requests\FeedingRejectRequest;
use App\Modules\Farms\Resources\FeedingRecordResource;
use App\Modules\Farms\Services\FeedingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedingController extends Controller
{
    public function __construct(private readonly FeedingService $feedings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return FeedingRecordResource::collection($this->feedings->paginate($request->query()));
    }

    public function store(FeedingRecordRequest $request): FeedingRecordResource
    {
        return new FeedingRecordResource($this->feedings->create($request->validated(), $request->user()));
    }

    public function show(FeedingRecord $feeding): FeedingRecordResource
    {
        return new FeedingRecordResource($feeding->load(['farmInformation', 'lines.foodItem', 'lines.stockLot', 'confirmation']));
    }

    public function update(FeedingRecordRequest $request, FeedingRecord $feeding): FeedingRecordResource
    {
        return new FeedingRecordResource($this->feedings->update($feeding, $request->validated(), $request->user()));
    }

    public function submit(Request $request, FeedingRecord $feeding): FeedingRecordResource
    {
        return new FeedingRecordResource($this->feedings->submit($feeding, $request->user()));
    }

    public function confirm(Request $request, FeedingRecord $feeding): FeedingRecordResource
    {
        return new FeedingRecordResource($this->feedings->confirm($feeding, $request->user()));
    }

    public function reject(FeedingRejectRequest $request, FeedingRecord $feeding): FeedingRecordResource
    {
        return new FeedingRecordResource($this->feedings->reject($feeding, $request->validated('reason'), $request->user()));
    }
}