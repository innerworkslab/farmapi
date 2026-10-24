<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Financial\Models\CashLedgerCategory;
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
    public static function initialKey(int $invoiceId): string
    {
        return 'purchase-invoice-initial-'.$invoiceId;
    }

    public function __construct(private readonly CashbookTransactionService $cash, private readonly FinancialControlService $controls) {}

    /** Store the initial payment amount without choosing or posting to a cashbook. */
    public function syncInitial(PurchaseInvoice $invoice, array $data, User $actor): void
    {
        $key = self::initialKey($invoice->id);
        $existing = PurchasePayment::where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->purchase_invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages(['cashbook_id' => 'Invalid original payment reference.']);
            }
            if ((int) $existing->branch_id !== (int) $invoice->branch_id || $existing->currency_code !== 'MMK') {
                throw ValidationException::withMessages(['paid_amount' => 'Initial payment details do not match this purchase.']);
            }
            if ($existing->state !== 'draft') {
                throw ValidationException::withMessages(['paid_amount' => 'The purchase payment has already been confirmed.']);
            }
        }
        $amount = Money::minor($data['paid_amount'] ?? $existing?->amount ?? 0);
        if ($amount < 0 || $amount > Money::minor($invoice->total_amount)) {
            throw ValidationException::withMessages(['paid_amount' => 'Paid amount must be between zero and the purchase total.']);
        }
        $category = CashLedgerCategory::where('normalized_name', 'purchases')->where('direction', 'out')->where('status', 'active')->first();
        if (! $category) {
            throw ValidationException::withMessages(['paid_amount' => 'Configure the active Purchases cash-out category.']);
        }
        if ($amount === 0) {
            if ($existing?->state === 'draft') {
                $existing->update(['state' => 'cancelled']);
            }
            $invoice->status = PurchaseInvoice::STATUS_CONFIRMED;
            $invoice->save();
            return;
        }

        $invoice->status = PurchaseInvoice::STATUS_OPEN;
        $invoice->save();
        PurchasePayment::updateOrCreate(['idempotency_key' => $key], [
            'purchase_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
            'cashbook_id' => null, 'category_id' => $category->id, 'reference' => $existing?->reference ?? 'PPAY-'.Str::upper((string) Str::ulid()),
            'currency_code' => 'MMK', 'business_date' => $invoice->invoice_date->toDateString(),
            'amount' => Money::decimal($amount), 'description' => 'Initial payment for '.$invoice->invoice_number,
            'created_by_id' => $actor->id, 'state' => 'draft',
        ]);
    }

    public function confirmInitial(PurchaseInvoice $invoice, int $cashbookId, User $actor): PurchasePayment
    {
        $payment = PurchasePayment::query()->where('purchase_invoice_id', $invoice->id)
            ->where('idempotency_key', self::initialKey($invoice->id))->lockForUpdate()->first();
        if (! $payment || $payment->state !== 'draft' || Money::minor($payment->amount) <= 0) {
            throw ValidationException::withMessages(['invoice' => 'Only purchases with an unpaid initial amount can be confirmed with a cashbook.']);
        }
        abort_unless($actor->can('purchasing.payments.create'), 403, 'You cannot post purchase payments.');
        $book = Cashbook::query()->find($cashbookId);
        if (! $book || $book->status !== 'active' || (int) $book->branch_id !== (int) $invoice->branch_id || $book->currency_code !== 'MMK') {
            throw ValidationException::withMessages(['cashbook_id' => 'Select an active MMK cashbook in the purchase branch.']);
        }
        app(CashbookService::class)->find($book, $actor);
        $payment->cashbook_id = $book->id;
        $payment->save();

        return $this->confirm($payment, $actor);
    }

    public function create(PurchaseInvoice $invoice, array $data, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            BranchAccess::authorize($actor, (int) $invoice->branch_id);
            $original = PurchasePayment::where('purchase_invoice_id', $invoice->id)->where('idempotency_key', self::initialKey($invoice->id))->first();
            $data['cashbook_id'] = $data['cashbook_id'] ?? $original?->cashbook_id;
            if (! $data['cashbook_id']) {
                throw ValidationException::withMessages(['cashbook_id' => 'Select a cashbook for this payable settlement.']);
            }
            $data['invoice_currency_code'] = $invoice->currency_code ?? 'MMK';
            $data['description'] = $data['description'] ?? 'Payable settlement for '.$invoice->invoice_number;
            $book = Cashbook::query()->findOrFail($data['cashbook_id']);
            app(CashbookService::class)->find($book, $actor);
            if ($book->branch_id !== $invoice->branch_id || $book->status !== 'active') {
                throw ValidationException::withMessages(['cashbook_id' => 'Choose an active cashbook in the invoice branch.']);
            }
            if ($book->currency_code !== 'MMK') {
                throw ValidationException::withMessages(['cashbook_id' => 'Purchase settlements use an MMK cashbook.']);
            }
            $category = CashLedgerCategory::where('normalized_name', 'purchases')->where('direction', 'out')->where('status', 'active')->first();
            if (! $category) {
                throw ValidationException::withMessages(['cashbook_id' => 'Configure the active Purchases cash-out category.']);
            }
            $data['category_id'] = $category->id;
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

                return $existing->state === 'draft' ? $this->confirm($existing, $actor) : $existing;
            }
            $this->assertInvoice($invoice, $amount);

            $payment = PurchasePayment::query()->create([
                'purchase_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
                'cashbook_id' => $book->id, 'category_id' => $data['category_id'],
                'reference' => 'PPAY-'.Str::upper((string) Str::ulid()), 'idempotency_key' => $data['idempotency_key'],
                'currency_code' => $currency, 'business_date' => $date, 'amount' => Money::decimal($amount),
                'description' => trim($data['description']), 'created_by_id' => $actor->id, 'state' => 'draft',
            ]);

            return $this->confirm($payment, $actor);
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
            if ($payment->idempotency_key !== self::initialKey($invoice->id)) {
                app(PurchasePayableService::class)->append($invoice, -Money::minor($payment->amount), 'invoice_settlement', 'payment-'.$payment->id, $actor, $payment->business_date->toDateString());
            }

            return $payment->refresh();
        });
    }

    public function reverse(PurchasePayment $payment, string $reason, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->purchase_invoice_id);
            $payment = PurchasePayment::query()->lockForUpdate()->findOrFail($payment->id);
            BranchAccess::authorize($actor, (int) $payment->branch_id);
            if ($payment->state === 'reversed') {
                return $payment;
            }
            if ($payment->state !== 'confirmed' || ! $payment->cashbook_transaction_id || Money::minor($payment->amount) <= 0) {
                throw ValidationException::withMessages(['payment' => 'Only posted positive payments can be reversed.']);
            }
            $this->cash->reverseForModule(CashbookTransaction::query()->findOrFail($payment->cashbook_transaction_id), 'purchase_payment', $reason, $actor);
            $payment->fill(['state' => 'reversed', 'reversal_reason' => trim($reason)])->save();
            app(PurchasePayableService::class)->append($invoice, Money::minor($payment->amount), 'invoice_payment_reversal', 'reverse-'.$payment->id, $actor);

            return $payment->refresh();
        });
    }

    public function cancel(PurchasePayment $payment, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->purchase_invoice_id);
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
        $original = PurchasePayment::where('purchase_invoice_id', $invoice->id)->where('idempotency_key', self::initialKey($invoice->id))->first();
        $currency = $invoice->currency_code ?? FinancialBranchSetting::query()->where('branch_id', $invoice->branch_id)->value('default_currency');
        if (! $currency) {
            $currencies = Cashbook::query()->where('branch_id', $invoice->branch_id)->where('status', 'active')->distinct()->pluck('currency_code')->filter()->values();
            if ($currencies->count() === 1) {
                $currency = $currencies->first();
            }
        }
        $cashbooks = Cashbook::query()->where('branch_id', $invoice->branch_id)->where('status', 'active')
            ->when($original, fn ($query) => $query->whereKey($original->cashbook_id))
            ->when($currency, fn ($query, string $effectiveCurrency) => $query->where('currency_code', $effectiveCurrency))
            ->orderBy('name')->get(['id', 'name', 'type', 'currency_code', 'branch_id']);

        return ['invoice_id' => $invoice->id, 'cashbook_id' => $original?->cashbook_id, 'currency_code' => $currency, 'total_amount' => Money::decimal(Money::minor($invoice->total_amount)),
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
        if (! in_array($invoice->status, [PurchaseInvoice::STATUS_OPEN, PurchaseInvoice::STATUS_CONFIRMED], true)) {
            throw ValidationException::withMessages(['invoice' => 'Cancelled invoices cannot receive payments.']);
        }
        if ($amount > Money::minor($invoice->total_amount) - $this->paid($invoice)) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the invoice outstanding amount.']);
        }
    }
}
