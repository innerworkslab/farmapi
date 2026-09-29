<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post') && ! $this->route('cashbook');

        return [
            'branch_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:branches,id'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(['cash', 'bank'])],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'bank_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_code' => [$creating ? 'required' : 'prohibited', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'opening_balance' => [$creating ? 'sometimes' : 'prohibited', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'effective_date' => [$creating ? 'required' : 'prohibited', 'date', 'before_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->has('name') && is_string($this->input('name'))) {
            $normalized['name'] = trim($this->input('name'));
        }
        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $normalized['currency_code'] = strtoupper(trim($this->input('currency_code')));
        }
        $this->merge($normalized);
    }
}
