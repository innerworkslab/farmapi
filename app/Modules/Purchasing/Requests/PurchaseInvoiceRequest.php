<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\BranchAccess;

class PurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($invoice = $this->route('invoice')) {
            BranchAccess::authorize($this->user(), (int) $invoice->branch_id);
        }
        $branchId = filter_var($this->input('branch_id'), FILTER_VALIDATE_INT);
        if ($branchId && $branchId > 0) {
            BranchAccess::authorize($this->user(), $branchId);
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['sometimes', 'string', 'max:100', Rule::unique('purchase_invoices', 'invoice_number')->ignore($this->route('invoice')?->id)],
            'invoice_date' => ['required', 'date'],
            'currency_code' => ['nullable', 'in:MMK'],
            'cashbook_id' => [Rule::requiredIf(fn () => ! $this->route('invoice')), 'nullable', 'integer', Rule::exists('cashbooks', 'id')->where('branch_id', $this->input('branch_id'))->where('status', 'active')->whereNull('deleted_at')],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
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
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'lines.*.discount_type' => ['nullable', 'string', Rule::in(['percentage', 'fixed'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.foc_type' => ['nullable', 'string', Rule::in(['percentage', 'quantity'])],
            'lines.*.foc_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.9999', 'decimal:0,4'],
            'lines.*.target_inventory_id' => ['prohibited'],
            'lines.*.target_farm_information_id' => ['nullable', 'integer', 'exists:farm_information,id'],
            'lines.*.target_location' => ['prohibited'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}
