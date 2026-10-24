<?php

namespace App\Modules\Purchasing\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseInvoiceConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');
        BranchAccess::authorize($this->user(), (int) $invoice->branch_id);

        return true;
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');

        return [
            'cashbook_id' => ['required', 'integer', Rule::exists('cashbooks', 'id')->where('branch_id', $invoice->branch_id)->where('currency_code', 'MMK')->where('status', 'active')->whereNull('deleted_at')],
        ];
    }
}
