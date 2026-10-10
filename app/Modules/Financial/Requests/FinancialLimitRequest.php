<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'maximum_amount' => ['required', 'numeric', 'min:0', 'regex:/^\\d{1,16}(?:\\.\\d{1,2})?$/'],
            'allow_self_approval' => ['required', 'boolean'],
        ];
    }
}
