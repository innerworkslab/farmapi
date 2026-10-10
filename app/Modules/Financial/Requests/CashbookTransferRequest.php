<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashbookTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_cashbook_id' => ['required', 'integer', 'exists:cashbooks,id'],
            'destination_cashbook_id' => ['required', 'integer', 'different:source_cashbook_id', 'exists:cashbooks,id'],
            'out_category_id' => ['required', 'integer', 'exists:cash_ledger_categories,id'],
            'in_category_id' => ['required', 'integer', 'exists:cash_ledger_categories,id'],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:100'],
        ];
    }
}
