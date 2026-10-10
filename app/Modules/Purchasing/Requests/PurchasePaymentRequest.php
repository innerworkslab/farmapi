<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cashbook_id' => ['required', 'integer', 'exists:cashbooks,id'],
            'category_id' => ['required', 'integer', 'exists:cash_ledger_categories,id'],
            'invoice_currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\\d{1,16}(?:\\.\\d{1,2})?$/'],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
