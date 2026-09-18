<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adjustment_number' => ['sometimes', 'string', 'max:100', Rule::unique('inventory_adjustments', 'adjustment_number')->ignore($this->route('adjustment')?->id)],
            'type' => ['required', 'string', Rule::in([
                'opening_balance',
                'stock_count_variance',
                'damage',
                'expiry',
                'disposal',
                'found_stock',
                'data_correction',
                'other',
                'reversal',
            ])],
            'adjustment_date' => ['required', 'date'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'farm_information_id' => ['nullable', 'integer', 'exists:farm_information,id'],
            'reason_type' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'attachment_path' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_number' => ['sometimes', 'integer', 'min:1'],
            'lines.*.category' => ['required', 'string', Rule::in(['food', 'medicine', 'equipment'])],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.location' => ['nullable', 'string', 'max:100'],
            'lines.*.stock_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'lines.*.stock_lot_id' => ['nullable', 'integer', 'exists:inventory_stock_lots,id'],
            'lines.*.equipment_instance_id' => ['nullable', 'integer', 'exists:inventory_equipment_instances,id'],
            'lines.*.system_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.adjustment_quantity' => ['nullable', 'numeric'],
            'lines.*.direction' => ['nullable', 'string', Rule::in(['in', 'out'])],
            'lines.*.new_identity' => ['nullable', 'boolean'],
            'lines.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'lines.*.supplier_batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.receipt_lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.manufacturing_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.lot_status' => ['nullable', 'string', Rule::in(['available', 'quarantined', 'damaged', 'expired', 'recalled', 'blocked'])],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.asset_tag' => ['nullable', 'string', 'max:100'],
            'lines.*.condition' => ['nullable', 'string', 'max:100'],
            'lines.*.lifecycle_status' => ['nullable', 'string', 'max:100'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('adjustment_number') && is_string($this->adjustment_number)) {
            $data['adjustment_number'] = trim($this->adjustment_number);
        }

        if ($this->has('type') && is_string($this->type)) {
            $data['type'] = trim(strtolower($this->type));
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }
}
