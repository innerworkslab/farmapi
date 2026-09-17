<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('medicine')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('medicines', 'code')->ignore($recordId)],
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'max:100'],
            'target_animal_type' => ['nullable', 'string', 'max:100'],
            'target_disease' => ['nullable', 'string', 'max:255'],
            'active_ingredient' => ['nullable', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string', 'max:100'],
            'dosage_unit' => ['nullable', 'string', 'max:100'],
            'recommended_dosage' => ['nullable', 'numeric', 'min:0'],
            'dosage_frequency' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'registration_number' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'registration_number')->ignore($recordId)],
            'package_size' => ['nullable', 'string', 'max:100'],
            'purchase_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'stock_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'usage_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'uom_conversion' => ['required', 'numeric', 'gt:0'],
            'barcode_sku' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'barcode_sku')->ignore($recordId)],
            'batch_tracking' => ['required', 'boolean'],
            'expiry_tracking' => ['required', 'boolean'],
            'cold_chain_required' => ['required', 'boolean'],
            'storage_temperature' => ['nullable', 'string', 'max:100'],
            'storage_instruction' => ['nullable', 'string', 'max:2000'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock_level' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['nullable', 'string', 'max:100'],
            'inventory_account' => ['nullable', 'string', 'max:100'],
            'medicine_expense_account' => ['nullable', 'string', 'max:100'],
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