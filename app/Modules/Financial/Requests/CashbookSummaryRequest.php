<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashbookSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date'],
            'as_of_date' => ['sometimes', 'date'],
            'cashbook_id' => ['sometimes', 'integer', 'exists:cashbooks,id'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'type' => ['sometimes', 'in:cash,bank'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'status' => ['sometimes', 'in:active,inactive,draft,confirmed,reversed'],
            'direction' => ['sometimes', 'in:in,out'],
            'category_id' => ['sometimes', 'integer', 'exists:cash_ledger_categories,id'],
            'source_type' => ['sometimes', 'string', 'max:40'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $this->merge(['currency_code' => strtoupper(trim($this->input('currency_code')))]);
        }
    }
}
