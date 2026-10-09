<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['branch_id' => ['nullable','integer'], 'farm_information_id' => ['nullable','integer'], 'status' => ['nullable',Rule::in(['draft','confirmed','cancelled'])], 'payment_status' => ['nullable',Rule::in(['unpaid','partially_paid','paid'])], 'from_date' => ['nullable','date'], 'to_date' => ['nullable','date'], 'search' => ['nullable','string','max:120'], 'per_page' => ['nullable','integer','min:1','max:100']]);
        return response()->json($this->sales->paginate($filters, $request->user()));
    }

    public function show(Sale $sale, Request $request): JsonResponse { return response()->json(['data' => $this->sales->find($sale, $request->user())]); }
    public function invoice(Sale $sale, Request $request): JsonResponse
    {
        $sale = $this->sales->find($sale, $request->user());
        return response()->json(['data' => ['document_type' => $sale->status === 'draft' ? 'draft' : 'invoice', 'sale' => $sale, 'customer' => $sale->customer_snapshot ?? ['name' => $sale->customer?->name], 'farm' => $sale->farm?->name, 'batch' => $sale->balance?->item?->name]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required','string','max:120','unique:sales,idempotency_key'], 'branch_id' => ['required','integer','exists:branches,id'], 'farm_information_id' => ['required','integer','exists:farm_information,id'], 'inventory_balance_id' => ['required','integer','exists:inventory_balances,id'], 'customer_id' => ['required','integer','exists:customers,id'], 'sale_date' => ['nullable','date'], 'quantity' => ['required','integer','min:1'], 'foc_quantity' => ['sometimes','integer','min:0'], 'unit_price' => ['required','numeric','gt:0','decimal:0,2'], 'discount_type' => [Rule::in(['none','percentage','fixed_amount'])], 'discount_value' => ['sometimes','numeric','min:0','decimal:0,2'], 'paid_amount' => ['sometimes','numeric','min:0','decimal:0,2'], 'cashbook_id' => ['nullable','integer','exists:cashbooks,id'], 'cash_category_id' => ['nullable','integer','exists:cash_ledger_categories,id']]);
        $sale = $this->sales->create($data, $request->user());
        return response()->json(['message' => 'Sale draft created.', 'data' => $sale], 201);
    }

    public function update(Sale $sale, Request $request): JsonResponse
    {
        $data = $request->validate(['farm_information_id' => ['sometimes','integer','exists:farm_information,id'], 'inventory_balance_id' => ['sometimes','integer','exists:inventory_balances,id'], 'customer_id' => ['sometimes','integer','exists:customers,id'], 'sale_date' => ['sometimes','date'], 'quantity' => ['sometimes','integer','min:1'], 'foc_quantity' => ['sometimes','integer','min:0'], 'unit_price' => ['sometimes','numeric','gt:0','decimal:0,2'], 'discount_type' => ['sometimes',Rule::in(['none','percentage','fixed_amount'])], 'discount_value' => ['sometimes','numeric','min:0','decimal:0,2'], 'paid_amount' => ['sometimes','numeric','min:0','decimal:0,2'], 'cashbook_id' => ['sometimes','nullable','integer','exists:cashbooks,id'], 'cash_category_id' => ['sometimes','nullable','integer','exists:cash_ledger_categories,id']]);
        return response()->json(['message' => 'Sale draft updated.', 'data' => $this->sales->updateDraft($sale, $data, $request->user())]);
    }

    public function confirm(Sale $sale, Request $request): JsonResponse { return response()->json(['message' => 'Sale confirmed and posted.', 'data' => $this->sales->confirm($sale, $request->user())]); }
    public function receive(Sale $sale, Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required','numeric','gt:0','decimal:0,2'], 'cashbook_id' => ['required','integer','exists:cashbooks,id'], 'cash_category_id' => ['required','integer','exists:cash_ledger_categories,id'], 'idempotency_key' => ['required','string','min:1','max:100']]);
        return response()->json(['message' => 'Receivable payment posted.', 'data' => $this->sales->receive($sale, $data, $request->user())]);
    }
    public function cancel(Sale $sale, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required','string','min:3','max:1000']]);
        return response()->json(['message' => 'Sale cancelled and reversed.', 'data' => $this->sales->cancel($sale, $data['reason'], $request->user())]);
    }
    public function destroy(Sale $sale, Request $request): JsonResponse { $this->sales->deleteDraft($sale, $request->user()); return response()->json(['message' => 'Draft sale deleted.']); }
    public function farms(Request $request): JsonResponse { $d = $request->validate(['branch_id' => ['required','integer','exists:branches,id']]); return response()->json(['data' => $this->sales->farms((int) $d['branch_id'], $request->user())]); }
    public function batches(Request $request): JsonResponse { $d = $request->validate(['farm_information_id' => ['required','integer','exists:farm_information,id']]); return response()->json(['data' => $this->sales->batches((int) $d['farm_information_id'], $request->user())]); }
    public function customers(Request $request): JsonResponse { $d = $request->validate(['branch_id' => ['required','integer','exists:branches,id'], 'search' => ['nullable','string','max:120']]); return response()->json(['data' => $this->sales->customers((int) $d['branch_id'], $request->user(), $d['search'] ?? null)]); }
}
