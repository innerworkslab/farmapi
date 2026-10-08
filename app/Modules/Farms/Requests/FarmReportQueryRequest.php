<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FarmReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d'], 'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from_date') ? ['after_or_equal:from_date'] : [])], 'animal_balance_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:health,mortality,medication,weight,correction,feeding,purchase,transfer,sale,production'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
