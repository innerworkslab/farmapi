<?php

namespace App\Modules\Financial\Requests;

use App\Modules\Financial\Models\CashLedgerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashLedgerCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = $category instanceof CashLedgerCategory ? $category->id : $category;
        $creating = $this->isMethod('post') && ! $category;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
            'direction' => [$creating ? 'required' : 'sometimes', Rule::in([CashLedgerCategory::DIRECTION_IN, CashLedgerCategory::DIRECTION_OUT])],
            'reversal_category_id' => ['sometimes', 'nullable', 'integer', 'exists:cash_ledger_categories,id', Rule::notIn(array_filter([$categoryId]))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
