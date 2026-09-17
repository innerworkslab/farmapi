<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('food')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('foods', 'code')->ignore($recordId)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'target_animal_type' => ['required', 'string', 'max:100'],
            'life_stage' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'feed_form' => ['nullable', 'string', 'max:100'],
            'purchase_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'stock_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'consumption_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'uom_conversion' => ['required', 'numeric', 'gt:0'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'batch_tracking' => ['required', 'boolean'],
            'expiry_tracking' => ['required', 'boolean'],
            'minimum_stock_level' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'inventory_account' => ['nullable', 'string', 'max:100'],
            'feed_expense_account' => ['nullable', 'string', 'max:100'],
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