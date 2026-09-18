<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseCancelRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:2000']]; }
}