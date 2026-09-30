<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashbookTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post') && ! $this->route('transaction');

        return [
            'cashbook_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cashbooks,id'],
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cash_ledger_categories,id'],
            'business_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'direction' => [$creating ? 'required' : 'sometimes', Rule::in(['in', 'out'])],
            'amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:9999999999999999.99'],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:2000'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => [$creating ? 'required' : 'prohibited', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->has('description') && is_string($this->input('description'))) {
            $normalized['description'] = trim($this->input('description'));
        }
        if ($this->has('external_reference') && is_string($this->input('external_reference'))) {
            $normalized['external_reference'] = trim($this->input('external_reference'));
        }
        if ($this->isMethod('post') && ! $this->route('transaction')) {
            $normalized['idempotency_key'] = $this->input('idempotency_key') ?? $this->header('Idempotency-Key');
        }
        $this->merge($normalized);
    }
}
