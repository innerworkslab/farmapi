<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Financial\Services\CashbookService;
use App\Modules\Financial\Services\CashbookTransactionService;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Financial\Support\Money;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchasePaymentService
{
    public function __construct(private readonly CashbookTransactionService $cash, private readonly FinancialControlService $controls) {}

    public function create(PurchaseInvoice $invoice, array $data, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            BranchAccess::authorize($actor, (int) $invoice->branch_id);
            $book = Cashbook::query()->findOrFail($data['cashbook_id']);
            app(CashbookService::class)->find($book, $actor);
            if ($book->branch_id !== $invoice->branch_id || $book->status !== 'active') {
                throw ValidationException::withMessages(['cashbook_id' => 'Choose an active cashbook in the invoice branch.']);
            }
            $amount = Money::minor($data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Payment must be positive.']);
            }
            $date = $data['business_date'] ?? $this->controls->businessDate((int) $invoice->branch_id);
            $currency = $data['invoice_currency_code'];
            if ($book->currency_code !== $currency || ($invoice->currency_code && $invoice->currency_code !== $currency)) {
                throw ValidationException::withMessages(['invoice_currency_code' => 'Invoice and cashbook currencies must match. Currency conversion is not supported.']);
            }
            $existing = PurchasePayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->purchase_invoice_id !== (int) $invoice->id || (int) $existing->cashbook_id !== (int) $book->id
                    || (int) $existing->category_id !== (int) $data['category_id'] || Money::minor($existing->amount) !== $amount
                    || $existing->business_date->toDateString() !== $date || $existing->currency_code !== $currency
                    || $existing->description !== trim($data['description'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was used for different purchase payment data.']);
                }

                return $existing;
            }
            $this->assertInvoice($invoice, $amount);

            return PurchasePayment::query()->create([
                'purchase_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
                'cashbook_id' => $book->id, 'category_id' => $data['category_id'],
                'reference' => 'PPAY-'.Str::upper((string) Str::ulid()), 'idempotency_key' => $data['idempotency_key'],
                'currency_code' => $currency, 'business_date' => $date, 'amount' => Money::decimal($amount),
                'description' => trim($data['description']), 'created_by_id' => $actor->id, 'state' => 'draft',
            ]);
        });
    }

    public function confirm(PurchasePayment $payment, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            // Same invoice-first order as create/edit prevents payments racing invoice changes.
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->purchase_invoice_id);
            $payment = PurchasePayment::query()->lockForUpdate()->findOrFail($payment->id);
            BranchAccess::authorize($actor, (int) $payment->branch_id);
            if ($payment->state === 'confirmed') {
                return $payment;
            }
            if ($payment->state !== 'draft') {
                throw ValidationException::withMessages(['payment' => 'Only draft payments can be confirmed.']);
            }
            $this->assertInvoice($invoice, Money::minor($payment->amount));
            if ($payment->business_date->lt($invoice->invoice_date)) {
                throw ValidationException::withMessages(['business_date' => 'Payment cannot precede the purchase invoice date.']);
            }
            if ($invoice->currency_code && $invoice->currency_code !== $payment->currency_code) {
                throw ValidationException::withMessages(['currency_code' => 'Invoice currency changed before confirmation.']);
            }
            $book = Cashbook::query()->findOrFail($payment->cashbook_id);
            if ($book->currency_code !== $payment->currency_code || (int) $book->branch_id !== (int) $invoice->branch_id) {
                throw ValidationException::withMessages(['cashbook_id' => 'Payment currency and branch must match the invoice.']);
            }
            $posting = $this->cash->postForModule([
                'cashbook_id' => $payment->cashbook_id, 'category_id' => $payment->category_id,
                'direction' => 'out', 'amount' => $payment->amount, 'business_date' => $payment->business_date->toDateString(),
                'description' => $payment->description, 'external_reference' => $payment->reference,
                'idempotency_key' => 'purchase-payment-'.$payment->id, 'created_by_id' => $payment->created_by_id,
            ], 'purchase_payment', $actor);
            $invoice->currency_code = $payment->currency_code;
            $invoice->save();
            $payment->fill(['state' => 'confirmed', 'cashbook_transaction_id' => $posting->id, 'confirmed_by_id' => $actor->id, 'confirmed_at' => now()])->save();

            return $payment->refresh();
        });
    }

    public function reverse(PurchasePayment $payment, string $reason, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->purchase_invoice_id);
            $payment = PurchasePayment::query()->lockForUpdate()->findOrFail($payment->id);
            BranchAccess::authorize($actor, (int) $payment->branch_id);
            if ($payment->state === 'reversed') {
                return $payment;
            }
            if ($payment->state !== 'confirmed') {
                throw ValidationException::withMessages(['payment' => 'Only confirmed payments can be reversed.']);
            }
            $this->cash->reverseForModule(CashbookTransaction::query()->findOrFail($payment->cashbook_transaction_id), 'purchase_payment', $reason, $actor);
            $payment->fill(['state' => 'reversed', 'reversal_reason' => trim($reason)])->save();

            return $payment->refresh();
        });
    }

    public function cancel(PurchasePayment $payment, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $payment = PurchasePayment::query()->lockForUpdate()->findOrFail($payment->id);
            BranchAccess::authorize($actor, (int) $payment->branch_id);
            if ($payment->state !== 'draft') {
                throw ValidationException::withMessages(['payment' => 'Only draft payments can be cancelled.']);
            }
            $payment->state = 'cancelled';
            $payment->save();

            return $payment;
        });
    }

    public function summary(PurchaseInvoice $invoice, User $actor): array
    {
        BranchAccess::authorize($actor, (int) $invoice->branch_id);
        $paid = $this->paid($invoice);
        $currency = $invoice->currency_code ?? FinancialBranchSetting::query()->where('branch_id', $invoice->branch_id)->value('default_currency');
        if (! $currency) {
            $currencies = Cashbook::query()->where('branch_id', $invoice->branch_id)->where('status', 'active')->distinct()->pluck('currency_code')->filter()->values();
            if ($currencies->count() === 1) {
                $currency = $currencies->first();
            }
        }
        $cashbooks = Cashbook::query()->where('branch_id', $invoice->branch_id)->where('status', 'active')
            ->when($currency, fn ($query, string $effectiveCurrency) => $query->where('currency_code', $effectiveCurrency))
            ->orderBy('name')->get(['id', 'name', 'type', 'currency_code', 'branch_id']);

        return ['invoice_id' => $invoice->id, 'currency_code' => $currency, 'total_amount' => Money::decimal(Money::minor($invoice->total_amount)),
            'paid_amount' => Money::decimal($paid), 'outstanding_amount' => Money::decimal($invoice->status === 'cancelled' ? 0 : Money::minor($invoice->total_amount) - $paid),
            'payments' => PurchasePayment::query()->where('purchase_invoice_id', $invoice->id)->orderBy('id')->get(),
            'eligible_cashbooks' => $cashbooks];
    }

    private function paid(PurchaseInvoice $invoice): int
    {
        return PurchasePayment::query()->where('purchase_invoice_id', $invoice->id)->where('state', 'confirmed')->get()->sum(fn ($p) => Money::minor($p->amount));
    }

    private function assertInvoice(PurchaseInvoice $invoice, int $amount): void
    {
        if ($invoice->status !== 'open') {
            throw ValidationException::withMessages(['invoice' => 'Only open invoices can receive payments.']);
        }
        if ($amount > Money::minor($invoice->total_amount) - $this->paid($invoice)) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the invoice outstanding amount.']);
        }
    }
}
