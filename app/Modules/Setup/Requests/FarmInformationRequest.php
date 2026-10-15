<?php

namespace App\Modules\Setup\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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
        $recordId = $this->route('farmInformation')?->id;
        $hasBarns = DB::table('farm_information')->whereNull('deleted_at')->where('branch_id', $this->input('branch_id'))->whereNotNull('house_barn')->where('house_barn', '<>', '')->exists();
        $hasPens = DB::table('farm_information')->whereNull('deleted_at')->where('branch_id', $this->input('branch_id'))->whereNotNull('pen_cage_pond')->where('pen_cage_pond', '<>', '')->exists();

        $barnRules = ['required', 'string', 'max:255'];
        $penRules = ['nullable', 'string', 'max:255'];
        if ($hasBarns) {
            $barnRules[] = Rule::exists('farm_information', 'house_barn')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $this->input('branch_id')));
        }
        if ($hasPens) {
            $penRules[] = Rule::exists('farm_information', 'pen_cage_pond')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $this->input('branch_id')));
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'house_barn' => $barnRules,
            'pen_cage_pond' => $penRules,
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
