<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FarmSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', 'in:active,inactive'], 'timezone' => ['sometimes', 'timezone'], 'daily_mortality_threshold' => ['nullable', 'numeric', 'between:0,100'], 'cumulative_mortality_threshold' => ['nullable', 'numeric', 'between:0,100'], 'feed_variance_threshold' => ['nullable', 'numeric', 'between:0,100'], 'feed_wastage_threshold' => ['nullable', 'numeric', 'between:0,100'], 'sickness_threshold' => ['nullable', 'numeric', 'between:0,100'], 'expiry_warning_days' => ['sometimes', 'integer', 'between:0,365'], 'veterinarian_id' => ['nullable', 'integer', 'exists:users,id']];
    }
}
