<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\CashbookTransfer;
use App\Modules\Financial\Requests\CashbookTransferRequest;
use App\Modules\Financial\Requests\FinancialReasonRequest;
use App\Modules\Financial\Resources\FinancialRecordResource;
use App\Modules\Financial\Services\CashbookTransferService;
use Illuminate\Http\Request;

class CashbookTransferController extends Controller
{
    public function __construct(private readonly CashbookTransferService $transfers) {}

    public function index(Request $request)
    {
        return FinancialRecordResource::collection($this->transfers->paginate($request->user()));
    }

    public function store(CashbookTransferRequest $request)
    {
        return (new FinancialRecordResource($this->transfers->create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function confirm(Request $request, CashbookTransfer $transfer)
    {
        return new FinancialRecordResource($this->transfers->confirm($transfer, $request->user()));
    }

    public function reverse(FinancialReasonRequest $request, CashbookTransfer $transfer)
    {
        return new FinancialRecordResource($this->transfers->reverse($transfer, $request->validated('reason'), $request->user()));
    }

    public function cancel(Request $request, CashbookTransfer $transfer)
    {
        return new FinancialRecordResource($this->transfers->cancel($transfer, $request->user()));
    }
}
