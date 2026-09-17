<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('uom')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('uoms', 'code')->ignore($recordId)],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:50'],
            'category' => ['required', 'string', Rule::in(['weight', 'volume', 'quantity', 'length'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->code) ? trim($this->code) : $this->code,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
        ]);
    }
}