<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Financial\Support\Money;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Setup\Models\SupplierPayableEntry;
use Illuminate\Validation\ValidationException;

class PurchasePayableService
{
    private function entries(PurchaseInvoice $invoice)
    {
        return SupplierPayableEntry::whereIn('entry_type', ['invoice_payable', 'invoice_adjustment', 'invoice_settlement', 'invoice_payment_reversal', 'invoice_cancel'])
            ->where('idempotency_key', 'like', 'purchase-invoice-'.$invoice->id.'-%');
    }

    public function sync(PurchaseInvoice $invoice, User $actor): void
    {
        $paid = $invoice->payments()->where('state', 'confirmed')->get()->sum(fn ($p) => Money::minor($p->amount));
        $remaining = Money::minor($invoice->total_amount) - $paid;
        $entries = $this->entries($invoice)->get();
        $recorded = $entries->sum(fn ($entry) => Money::minor($entry->amount));
        if ($remaining !== $recorded) {
            $this->append($invoice, $remaining - $recorded, $entries->isEmpty() ? 'invoice_payable' : 'invoice_adjustment',
                $entries->isEmpty() ? 'initial' : 'adjust-v'.$invoice->version, $actor, $invoice->invoice_date->toDateString());
        }
    }

    public function cancel(PurchaseInvoice $invoice, User $actor): void
    {
        $balance = $this->entries($invoice)->get()->sum(fn ($entry) => Money::minor($entry->amount));
        $this->append($invoice, -$balance, 'invoice_cancel', 'cancel', $actor);
    }

    public function append(PurchaseInvoice $invoice, int $minor, string $type, string $suffix, User $actor, ?string $date = null): void
    {
        if ($minor === 0) {
            return;
        }
        $key = 'purchase-invoice-'.$invoice->id.'-'.$suffix;
        $existing = SupplierPayableEntry::where('idempotency_key', $key)->first();
        if ($existing) {
            if (Money::minor($existing->amount) !== $minor) {
                throw ValidationException::withMessages(['payable' => 'Payable reference was used for another amount.']);
            }
            return;
        }
        $entry = SupplierPayableEntry::create(['supplier_id' => $invoice->supplier_id, 'branch_id' => $invoice->branch_id,
            'entry_type' => $type, 'amount' => Money::decimal($minor), 'currency_code' => $invoice->currency_code,
            'business_date' => $date ?? app(FinancialControlService::class)->businessDate($invoice->branch_id),
            'reference' => $invoice->invoice_number, 'idempotency_key' => $key, 'created_by_id' => $actor->id]);
        activity('purchasing')->performedOn($invoice)->causedBy($actor)->withProperties(['payable_entry_id' => $entry->id, 'amount' => $entry->amount, 'entry_type' => $type])->log('Purchase payable recorded');
    }
}
