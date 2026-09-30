<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StaffIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', 'in:active,inactive'],
            'employment_status' => ['sometimes', 'in:employed,terminated'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
