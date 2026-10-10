<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'enabled_currencies' => ['required', 'array', 'min:1'],
            'enabled_currencies.*' => ['required', 'distinct', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all'],
            'require_open_period' => ['required', 'boolean'],
            'require_approval_limit' => ['required', 'boolean'],
        ];
    }
}
