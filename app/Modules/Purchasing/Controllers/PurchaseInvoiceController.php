<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Requests\PurchaseCancelRequest;
use App\Modules\Purchasing\Requests\PurchaseInvoiceRequest;
use App\Modules\Purchasing\Resources\PurchaseInvoiceResource;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseInvoiceController extends Controller
{
    public function __construct(private readonly PurchaseInvoiceService $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseInvoiceResource::collection($this->invoices->paginate($request->query()));
    }

    public function store(PurchaseInvoiceRequest $request): PurchaseInvoiceResource
    {
        return new PurchaseInvoiceResource($this->invoices->create($request->validated(), $request->user()));
    }

    public function show(PurchaseInvoice $invoice): PurchaseInvoiceResource
    {
        return new PurchaseInvoiceResource($invoice->load(['supplier', 'lines.item']));
    }

    public function update(PurchaseInvoiceRequest $request, PurchaseInvoice $invoice): PurchaseInvoiceResource
    {
        return new PurchaseInvoiceResource($this->invoices->update($invoice, $request->validated()));
    }

    public function cancel(PurchaseCancelRequest $request, PurchaseInvoice $invoice): PurchaseInvoiceResource
    {
        return new PurchaseInvoiceResource($this->invoices->cancel($invoice, $request->validated('reason'), $request->user()));
    }
}