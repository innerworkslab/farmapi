<?php

namespace App\Modules\Setup\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FarmInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branchId = $this->input('branch_id') ?? $this->route('farmInformation')?->branch_id;
        BranchAccess::authorize($this->user(), $branchId ? (int) $branchId : null);

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'house_barn' => ['prohibited'],
            'pen_cage_pond' => ['prohibited'],
            'house_barn_id' => ['nullable', 'integer', 'required_without:pen_cage_pond_id', Rule::exists('house_barns', 'id')->where('branch_id', $this->input('branch_id'))->where('status', 'active')->whereNull('deleted_at')],
            'pen_cage_pond_id' => ['nullable', 'integer', 'required_without:house_barn_id', Rule::exists('pen_cage_ponds', 'id')->where('branch_id', $this->input('branch_id'))->where('status', 'active')->whereNull('deleted_at')],
            'current_animal_id' => ['nullable', 'integer', 'exists:animals,id'],
            'responsible_employee_id' => ['nullable', 'integer', 'exists:users,id'],
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
