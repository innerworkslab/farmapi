<?php

namespace App\Modules\Setup\Requests;

use App\Modules\Setup\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_code' => ['prohibited'],
            'normalized_staff_code' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'employment_status' => ['required', Rule::in([Staff::EMPLOYMENT_EMPLOYED, Staff::EMPLOYMENT_TERMINATED])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('name'))) {
            $data['name'] = trim($this->input('name'));
        }
        if (is_string($this->input('phone_number'))) {
            $phone = trim($this->input('phone_number'));
            $data['phone_number'] = $phone === '' ? null : $phone;
        }
        $this->merge($data);
    }
}
