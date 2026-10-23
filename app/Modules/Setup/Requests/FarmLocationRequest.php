<?php

namespace App\Modules\Setup\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FarmLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        BranchAccess::authorize($this->user(), (int) $this->input('branch_id'));

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'house_barn_id' => $this->route('location_kind') === 'pen-cage-ponds'
                ? ['nullable', 'integer', Rule::exists('house_barns', 'id')->where('branch_id', $this->input('branch_id'))->where('status', 'active')->whereNull('deleted_at')]
                : ['prohibited'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
