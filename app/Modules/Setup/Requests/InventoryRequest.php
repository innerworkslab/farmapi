<?php

namespace App\Modules\Setup\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branchId = $this->input('branch_id') ?? $this->route('inventory')?->branch_id;
        BranchAccess::authorize($this->user(), $branchId ? (int) $branchId : null);

        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('inventory')?->id;
        $hasZones = DB::table('inventories')->whereNull('deleted_at')->where('branch_id', $this->input('branch_id'))->whereNotNull('building_zone')->where('building_zone', '<>', '')->exists();
        $hasRacks = DB::table('inventories')->whereNull('deleted_at')->where('branch_id', $this->input('branch_id'))->whereNotNull('rack_bin')->where('rack_bin', '<>', '')->exists();
        $zoneRules = ['nullable', 'string', 'max:255'];
        $rackRules = ['nullable', 'string', 'max:255'];
        if ($hasZones) {
            $zoneRules[] = Rule::exists('inventories', 'building_zone')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $this->input('branch_id')));
        }
        if ($hasRacks) {
            $rackRules[] = Rule::exists('inventories', 'rack_bin')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $this->input('branch_id')));
        }

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('inventories', 'code')->ignore($recordId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['feed', 'medicine', 'equipment', 'general'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'physical_address' => ['nullable', 'string', 'max:2000'],
            'building_zone' => $zoneRules,
            'rack_bin' => $rackRules,
            'allowed_item_categories' => ['nullable', 'array'],
            'allowed_item_categories.*' => ['string', 'max:100'],
            'inventory_gl_account' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
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
