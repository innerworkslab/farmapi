<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashEntryClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['report_treatment' => ['required', 'in:income,expense,excluded'], 'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
