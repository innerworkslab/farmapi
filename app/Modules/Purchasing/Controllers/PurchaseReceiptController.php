<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Requests\PurchaseReceiptRequest;
use App\Modules\Purchasing\Resources\PurchaseReceiptResource;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseReceiptController extends Controller
{
    public function __construct(private readonly PurchaseReceiptService $receipts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseReceiptResource::collection($this->receipts->paginate($request->query()));
    }

    public function store(PurchaseReceiptRequest $request): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($this->receipts->create($request->validated(), $request->user()));
    }

    public function show(PurchaseReceipt $receipt): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($receipt->load(['invoice.supplier', 'lines.item']));
    }

    public function confirm(Request $request, PurchaseReceipt $receipt): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($this->receipts->confirm($receipt, $request->user()));
    }
}