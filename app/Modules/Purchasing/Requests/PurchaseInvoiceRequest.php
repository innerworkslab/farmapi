<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'invoice_number' => ['sometimes', 'string', 'max:100', Rule::unique('purchase_invoices', 'invoice_number')->ignore($this->route('invoice')?->id)],
            'invoice_date' => ['required', 'date'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_number' => ['sometimes', 'integer', 'min:1'],
            'lines.*.category' => ['required', 'string', Rule::in(['food', 'medicine', 'equipment', 'animal'])],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.purchase_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'lines.*.stock_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'lines.*.conversion_factor' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'string', Rule::in(['percentage', 'fixed'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foc_type' => ['nullable', 'string', Rule::in(['percentage', 'quantity'])],
            'lines.*.foc_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.target_inventory_id' => ['nullable', 'integer', 'exists:inventories,id'],
            'lines.*.target_farm_information_id' => ['nullable', 'integer', 'exists:farm_information,id'],
            'lines.*.target_location' => ['nullable', 'string', 'max:100'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}