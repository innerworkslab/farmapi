<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedingRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}