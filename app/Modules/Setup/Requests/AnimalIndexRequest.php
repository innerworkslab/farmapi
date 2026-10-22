<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnimalIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'animal_type_id' => ['nullable', 'integer', 'min:1'],
            'animal_category_id' => ['nullable', 'integer', 'min:1'],
            'animal_breed_id' => ['nullable', 'integer', 'min:1'],
            'gender' => ['nullable', 'string', 'max:50'],
            'tracking_type' => ['nullable', Rule::in(['batch', 'individual'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'type' => ['prohibited'], 'category' => ['prohibited'], 'breed' => ['prohibited'],
        ];
    }
}
