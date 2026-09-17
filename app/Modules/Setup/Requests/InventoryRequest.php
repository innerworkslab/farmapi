<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('inventory')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('inventories', 'code')->ignore($recordId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['feed', 'medicine', 'equipment', 'general'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'physical_address' => ['nullable', 'string', 'max:2000'],
            'building_zone' => ['nullable', 'string', 'max:255'],
            'rack_bin' => ['nullable', 'string', 'max:255'],
            'allowed_item_categories' => ['nullable', 'array'],
            'allowed_item_categories.*' => ['string', 'max:100'],
            'inventory_gl_account' => ['nullable', 'string', 'max:100'],
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