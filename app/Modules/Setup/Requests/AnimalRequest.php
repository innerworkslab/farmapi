<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnimalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('animal')?->id;
        $typeId = filter_var($this->input('animal_type_id'), FILTER_VALIDATE_INT) ?: 0;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('animals', 'code')->ignore($recordId)],
            'tracking_type' => ['required', 'string', Rule::in(['batch', 'individual'])],
            'ear_tag_rfid_number' => ['nullable', 'required_if:tracking_type,individual', 'string', 'max:100', Rule::unique('animals', 'ear_tag_rfid_number')->ignore($recordId)],
            'batch_flock_number' => ['nullable', 'required_if:tracking_type,batch', 'string', 'max:100', Rule::unique('animals', 'batch_flock_number')->ignore($recordId)],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['prohibited'],
            'category' => ['prohibited'],
            'breed' => ['prohibited'],
            'animal_type_id' => ['required', 'integer', Rule::exists('animal_types', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'animal_category_id' => ['required', 'integer', Rule::exists('animal_categories', 'id')->where('animal_type_id', $typeId)->where('status', 'active')->whereNull('deleted_at')],
            'animal_breed_id' => ['required', 'integer', Rule::exists('animal_breeds', 'id')->where('animal_type_id', $typeId)->where('status', 'active')->whereNull('deleted_at')],
            'gender' => ['required', 'string', 'max:50'],
            'color_marking' => ['nullable', 'string', 'max:255'],
            'photo_path' => ['nullable', 'string', 'max:500'],
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
