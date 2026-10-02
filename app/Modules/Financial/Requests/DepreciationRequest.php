<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepreciationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post') && ! $this->route('depreciation');

        return [
            'asset_name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],
            'asset_category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:asset_categories,id'],
            'asset_price' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:9999999999999999.99', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'monthly_amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:9999999999999999.99', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'start_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'cashbook_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cashbooks,id'],
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cash_ledger_categories,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => [$creating ? 'required' : 'prohibited', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['asset_name', 'description', 'idempotency_key'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }
        if ($this->isMethod('post') && ! $this->route('depreciation')) {
            $data['idempotency_key'] = $this->input('idempotency_key') ?? $this->header('Idempotency-Key');
        }
        $this->merge($data);
    }
}
