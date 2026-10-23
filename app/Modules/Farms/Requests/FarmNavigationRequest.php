<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FarmNavigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'house_barn_id' => ['nullable', 'integer', 'min:1'],
            'pen_cage_pond_id' => ['nullable', 'integer', 'min:1'],
            'house_barn' => ['prohibited'],
            'pen_cage_pond' => ['prohibited'],
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'animal_type_id' => ['nullable', 'integer', 'min:1'],
            'animal_category_id' => ['nullable', 'integer', 'min:1'],
            'animal_breed_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'view' => ['nullable', Rule::in(['all', 'batch', 'individual'])],
            'tracking_type' => ['nullable', Rule::in(['batch', 'individual'])],
            'location' => ['nullable', 'string', 'max:100'],
            'health_status' => ['nullable', 'string', 'max:50'],
            'life_stage' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'animal_type' => ['prohibited'], 'farm_type' => ['prohibited'], 'breed' => ['prohibited'],
        ];
    }
}
