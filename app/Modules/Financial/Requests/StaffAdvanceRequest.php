<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StaffAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post') && ! $this->route('staffAdvance');
        return [
            'staff_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:staff,id'],
            'principal_amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:9999999999999999.99', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'business_date' => [$creating ? 'required' : 'sometimes', 'date', 'before_or_equal:today'],
            'cashbook_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cashbooks,id'],
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:cash_ledger_categories,id'],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:2000'],
            'idempotency_key' => [$creating ? 'required' : 'prohibited', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['description', 'idempotency_key'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }
        if ($this->isMethod('post') && ! $this->route('staffAdvance')) {
            $data['idempotency_key'] = $this->input('idempotency_key') ?? $this->header('Idempotency-Key');
        }
        $this->merge($data);
    }
}
