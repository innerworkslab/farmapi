<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Requests\CashbookDeactivationRequest;
use App\Modules\Financial\Requests\CashbookRequest;
use App\Modules\Financial\Requests\CashbookSummaryRequest;
use App\Modules\Financial\Resources\CashbookLedgerEntryResource;
use App\Modules\Financial\Resources\CashbookResource;
use App\Modules\Financial\Services\CashbookReportingService;
use App\Modules\Financial\Services\CashbookService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashbookController extends Controller
{
    public function __construct(
        private readonly CashbookService $cashbooks,
        private readonly CashbookReportingService $reports,
    ) {}

    public function index(CashbookSummaryRequest $request): AnonymousResourceCollection
    {
        return CashbookResource::collection($this->cashbooks->paginate($request->query(), $request->user()));
    }

    public function store(CashbookRequest $request): CashbookResource
    {
        $cashbook = $this->cashbooks->create($request->validated(), $request->user());

        return new CashbookResource($cashbook);
    }

    public function show(Cashbook $cashbook, \Illuminate\Http\Request $request): CashbookResource
    {
        return new CashbookResource($this->cashbooks->find($cashbook, $request->user()));
    }

    public function update(CashbookRequest $request, Cashbook $cashbook): CashbookResource
    {
        return new CashbookResource($this->cashbooks->update($cashbook, $request->validated(), $request->user()));
    }

    public function deactivate(CashbookDeactivationRequest $request, Cashbook $cashbook): CashbookResource
    {
        return new CashbookResource($this->cashbooks->deactivate($cashbook, $request->validated('reason'), $request->user()));
    }

    public function ledger(CashbookSummaryRequest $request, Cashbook $cashbook): AnonymousResourceCollection
    {
        return CashbookLedgerEntryResource::collection($this->cashbooks->ledger($cashbook, $request->query(), $request->user()));
    }

    public function dailySummary(CashbookSummaryRequest $request, Cashbook $cashbook): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->reports->dailySummary($cashbook, $request->validated(), $request->user())]);
    }
}
