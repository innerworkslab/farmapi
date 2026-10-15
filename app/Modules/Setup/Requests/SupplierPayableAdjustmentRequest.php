<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierPayableAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:150'],
        ];
    }
}
