<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['plan_date' => ['required', 'date_format:Y-m-d'], 'animal_balance_id' => ['required', 'integer', 'exists:inventory_balances,id'], 'food_item_id' => ['required', 'integer', 'exists:items,id'], 'stock_uom_id' => ['required', 'integer', 'exists:uoms,id'], 'quantity' => ['required', 'numeric', 'min:0']];
    }
}
