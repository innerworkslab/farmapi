<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Requests\CashbookSummaryRequest;
use App\Modules\Financial\Services\CashbookReportingService;
use Illuminate\Http\JsonResponse;

class CashbookReportController extends Controller
{
    public function __construct(private readonly CashbookReportingService $reports) {}

    public function consolidated(CashbookSummaryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->consolidated($request->validated(), $request->user())]);
    }

    public function categories(CashbookSummaryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->categorySummary($request->validated(), $request->user())]);
    }
}
