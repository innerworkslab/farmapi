<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('supplier')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($recordId)],
            'type' => ['nullable', 'string', Rule::in(['food', 'medicine', 'animal', 'equipment', 'service'])],
            'types' => ['required_without:type', 'array', 'min:1'],
            'types.*' => ['required', 'string', Rule::in(['food', 'medicine', 'animal', 'equipment', 'service'])],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:50'],
            'alternative_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'township' => ['nullable', 'string', 'max:255'],
            'state_region' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'supplied_categories' => ['nullable', 'array'],
            'supplied_categories.*' => ['string', 'max:100'],
            'preferred_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => [Rule::requiredIf(fn () => (float) $this->input('opening_balance', 0) > 0), 'nullable', 'string', Rule::in(['debit', 'credit', 'none'])],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'opening_balance_currency_code' => [Rule::requiredIf(fn () => (float) $this->input('opening_balance', 0) > 0), 'nullable', 'regex:/^[A-Z]{3}$/'],
            'opening_balance_date' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->code) ? trim($this->code) : $this->code,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'type' => $this->input('type') ?? (is_array($this->input('types')) ? ($this->input('types')[0] ?? null) : null),
            'types' => $this->input('types') ?? (is_string($this->input('type')) ? [$this->input('type')] : null),
        ]);
    }
}
