<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FarmActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $field = in_array($this->route()->getActionMethod(), ['completeFollowUp', 'investigate']) ? 'notes' : 'reason';

        return [$field => ['required', 'string', 'max:4000']];
    }
}
