<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Requests\CashbookReversalRequest;
use App\Modules\Financial\Requests\CashbookSummaryRequest;
use App\Modules\Financial\Requests\CashbookTransactionRequest;
use App\Modules\Financial\Resources\CashbookTransactionResource;
use App\Modules\Financial\Services\CashbookService;
use App\Modules\Financial\Services\CashbookTransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashbookTransactionController extends Controller
{
    public function __construct(private readonly CashbookTransactionService $transactions, private readonly CashbookService $cashbooks) {}

    public function index(CashbookSummaryRequest $request): AnonymousResourceCollection
    {
        return CashbookTransactionResource::collection($this->cashbooks->transactions($request->validated(), $request->user()));
    }

    public function store(CashbookTransactionRequest $request): CashbookTransactionResource
    {
        return new CashbookTransactionResource($this->transactions->createDraft($request->validated(), $request->user()));
    }

    public function show(CashbookTransaction $transaction, Request $request): CashbookTransactionResource
    {
        return new CashbookTransactionResource($this->transactions->find($transaction, $request->user()));
    }

    public function update(CashbookTransactionRequest $request, CashbookTransaction $transaction): CashbookTransactionResource
    {
        return new CashbookTransactionResource($this->transactions->updateDraft($transaction, $request->validated(), $request->user()));
    }

    public function confirm(CashbookTransaction $transaction, Request $request): CashbookTransactionResource
    {
        return new CashbookTransactionResource($this->transactions->confirm($transaction, $request->user()));
    }

    public function reverse(CashbookReversalRequest $request, CashbookTransaction $transaction): CashbookTransactionResource
    {
        return new CashbookTransactionResource($this->transactions->reverse($transaction, $request->validated('reason'), $request->user()));
    }
}
