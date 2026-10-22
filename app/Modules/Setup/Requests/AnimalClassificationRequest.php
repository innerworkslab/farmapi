<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnimalClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'animal_type_id' => [$this->route('classification_kind') === 'animal-types' ? 'prohibited' : 'required', 'integer', Rule::exists('animal_types', 'id')->whereNull('deleted_at')->where('status', 'active')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }
}
