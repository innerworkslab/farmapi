<?php

namespace App\Modules\Financial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepreciationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'cashbook_id' => ['sometimes', 'integer', 'exists:cashbooks,id'],
            'category_id' => ['sometimes', 'integer', 'exists:cash_ledger_categories,id'],
            'asset_category_id' => ['sometimes', 'integer', 'exists:asset_categories,id'],
            'status' => ['sometimes', 'string', 'in:draft,active,completed,cancelled'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
