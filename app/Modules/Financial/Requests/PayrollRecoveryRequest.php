<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayrollRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'payroll_reference' => ['required', 'string', 'min:1', 'max:120'],
            'evidence_reference' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:100'],
        ];
    }
}
