<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedingRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feeding_number' => ['sometimes', 'string', 'max:100', Rule::unique('farm_feeding_records', 'feeding_number')->ignore($this->route('feeding')?->id)],
            'feeding_date' => ['required', 'date'],
            'feeding_time' => ['nullable', 'date_format:H:i'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'farm_information_id' => ['required', 'integer', 'exists:farm_information,id'],
            'animal_balance_id' => ['required', 'integer', 'exists:inventory_balances,id'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_number' => ['sometimes', 'integer', 'min:1'],
            'lines.*.food_item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'lines.*.stock_lot_id' => ['nullable', 'integer', 'exists:inventory_stock_lots,id'],
            'lines.*.source_location' => ['nullable', 'string', 'max:100'],
            'lines.*.stock_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.wastage_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('feeding_number') && is_string($this->feeding_number)) {
            $data['feeding_number'] = trim($this->feeding_number);
        }

        if ($this->has('feeding_time') && is_string($this->feeding_time) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $this->feeding_time)) {
            $data['feeding_time'] = substr($this->feeding_time, 0, 5);
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }
}