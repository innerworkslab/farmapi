<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashbookReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => is_string($this->reason) ? trim($this->reason) : $this->reason]);
    }
}
