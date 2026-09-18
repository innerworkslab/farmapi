<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'receipt_number' => ['sometimes', 'string', 'max:100', Rule::unique('purchase_receipts', 'receipt_number')->ignore($this->route('receipt')?->id)],
            'purchase_invoice_id' => ['required', 'integer', 'exists:purchase_invoices,id'],
            'receipt_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:150', Rule::unique('purchase_receipts', 'idempotency_key')->ignore($this->route('receipt')?->id)],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_invoice_line_id' => ['required', 'integer', 'exists:purchase_invoice_lines,id'],
            'lines.*.line_number' => ['sometimes', 'integer', 'min:1'],
            'lines.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.accepted_foc_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.target_inventory_id' => ['nullable', 'integer', 'exists:inventories,id'],
            'lines.*.target_farm_information_id' => ['nullable', 'integer', 'exists:farm_information,id'],
            'lines.*.target_location' => ['nullable', 'string', 'max:100'],
            'lines.*.supplier_batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.receipt_lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.manufacturing_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.cold_chain_required' => ['nullable', 'boolean'],
            'lines.*.cold_chain_status' => ['nullable', 'string', Rule::in(['compliant', 'breached', 'unknown'])],
            'lines.*.observed_temperature' => ['nullable', 'numeric'],
            'lines.*.temperature_uom' => ['nullable', 'string', 'max:20'],
            'lines.*.exception_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}