<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Requests\FinancialReasonRequest;
use App\Modules\Financial\Resources\FinancialRecordResource;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Purchasing\Requests\PurchasePaymentRequest;
use App\Modules\Purchasing\Services\PurchasePaymentService;
use Illuminate\Http\Request;

class PurchasePaymentController extends Controller
{
    public function __construct(private readonly PurchasePaymentService $payments) {}

    public function index(Request $request, PurchaseInvoice $invoice)
    {
        return response()->json(['data' => $this->payments->summary($invoice, $request->user())]);
    }

    public function store(PurchasePaymentRequest $request, PurchaseInvoice $invoice)
    {
        return (new FinancialRecordResource($this->payments->create($invoice, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function confirm(Request $request, PurchasePayment $payment)
    {
        return new FinancialRecordResource($this->payments->confirm($payment, $request->user()));
    }

    public function reverse(FinancialReasonRequest $request, PurchasePayment $payment)
    {
        return new FinancialRecordResource($this->payments->reverse($payment, $request->validated('reason'), $request->user()));
    }

    public function cancel(Request $request, PurchasePayment $payment)
    {
        return new FinancialRecordResource($this->payments->cancel($payment, $request->user()));
    }
}
