<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\CashbookTransfer;
use App\Modules\Financial\Models\Depreciation;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffAdvanceLedgerEntry;
use App\Modules\Financial\Models\StaffPayrollRecovery;
use App\Modules\Financial\Support\Money;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialReconciliationService
{
    public function assertCashbook(Cashbook $cashbook): void
    {
        $expected = Money::minor($cashbook->opening_balance);
        foreach ($cashbook->entries()->where('source_type', '<>', 'opening_balance')->lockForUpdate()->get(['direction', 'amount']) as $entry) {
            $expected += ($entry->direction === 'in' ? 1 : -1) * Money::minor($entry->amount);
        }
        $actual = Money::minor($cashbook->entries()->orderByDesc('id')->lockForUpdate()->value('running_balance') ?? $cashbook->opening_balance);
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['reconciliation' => 'Cashbook balance does not reconcile to its movements. Posting is blocked until investigated.']);
        }
    }

    public function run(array $filters, User $actor): array
    {
        if (! empty($filters['branch_id'])) {
            BranchAccess::authorize($actor, (int) $filters['branch_id']);
        }

        // A repeatable snapshot keeps cross-module checks coherent while other branches post.
        return DB::transaction(function () use ($filters, $actor) {
            $checks = [];
            $add = function (string $check, string $type, int $id, int $expected, int $actual, ?string $currency = null) use (&$checks): void {
                $checks[] = ['check' => $check, 'record_type' => $type, 'record_id' => $id, 'currency_code' => $currency,
                    'expected' => Money::decimal($expected), 'actual' => Money::decimal($actual),
                    'delta' => Money::decimal($actual - $expected), 'passed' => $expected === $actual];
            };
            $scope = fn ($query) => BranchAccess::scope($query, $actor)->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
            $books = $scope(Cashbook::query())->get()->keyBy('id');
            $cashTransactions = CashbookTransaction::query()->whereIn('cashbook_id', $books->modelKeys())->with(['ledgerEntry', 'reversedByTransaction'])->get()->keyBy('id');
            $cashEntries = CashbookLedgerEntry::query()->whereIn('cashbook_id', $books->modelKeys())->orderBy('id')->get()->groupBy('cashbook_id');
            $staffEntries = $scope(StaffAdvanceLedgerEntry::query())->orderBy('id')->get();
            $loanEntries = $staffEntries->groupBy('staff_advance_id');
            $payroll = $scope(StaffPayrollRecovery::query())->get()->keyBy('id');
            $staffBalances = [];
            $visibleStaffEntryIds = array_flip($staffEntries->modelKeys());
            foreach (DB::table('staff_advance_ledger_entries')->whereIn('staff_id', $staffEntries->pluck('staff_id')->unique())->orderBy('id')->get() as $entry) {
                $staffBalances[$entry->staff_id] = ($staffBalances[$entry->staff_id] ?? 0) + ($entry->effect === 'addition' ? 1 : -1) * Money::minor($entry->amount);
                if (isset($visibleStaffEntryIds[$entry->id])) {
                    $add('staff_running_balance', 'staff_advance_entry', $entry->id, $staffBalances[$entry->staff_id], Money::minor($entry->running_balance), $entry->currency_code);
                }
            }
            foreach ($books as $book) {
                $balance = 0;
                $openings = 0;
                foreach ($cashEntries->get($book->id, collect()) as $entry) {
                    $entry->setRelation('transaction', $cashTransactions->get($entry->cashbook_transaction_id));
                    if ($entry->source_type === 'opening_balance') {
                        $openings++;
                        $add('opening_amount', 'cashbook_entry', $entry->id, Money::minor($book->opening_balance), Money::minor($entry->amount), $book->currency_code);
                    }
                    $balance += ($entry->direction === 'in' ? 1 : -1) * Money::minor($entry->amount);
                    $add('running_balance', 'cashbook_entry', $entry->id, $balance, Money::minor($entry->running_balance), $book->currency_code);
                    if ($entry->source_type !== 'opening_balance') {
                        $valid = $entry->transaction && in_array($entry->transaction->status, ['confirmed', 'reversed'], true)
                            && $entry->transaction->reference === $entry->reference && $entry->transaction->direction === $entry->direction
                            && $entry->transaction->source_type === $entry->source_type
                            && (int) $entry->transaction->cashbook_id === (int) $book->id
                            && (int) $entry->transaction->category_id === (int) $entry->category_id
                            && $entry->transaction->business_date->toDateString() === $entry->entry_date->toDateString()
                            && Money::minor($entry->transaction->amount) === Money::minor($entry->amount);
                        $add('cash_source_link', 'cashbook_entry', $entry->id, 1, $valid ? 1 : 0);
                    }
                }
                $add('opening_entry_count', 'cashbook', $book->id, 1, $openings);
                foreach ($cashTransactions->where('cashbook_id', $book->id)->whereIn('status', ['confirmed', 'reversed']) as $transaction) {
                    $add('posted_cash_has_entry', 'cashbook_transaction', $transaction->id, 1, $transaction->ledgerEntry ? 1 : 0);
                    if ($transaction->status === 'reversed') {
                        $reverse = $transaction->reversedByTransaction;
                        $valid = $reverse && $reverse->status === 'confirmed' && $reverse->cashbook_id === $transaction->cashbook_id
                            && $reverse->direction !== $transaction->direction && Money::minor($reverse->amount) === Money::minor($transaction->amount);
                        $add('cash_reversal_pair', 'cashbook_transaction', $transaction->id, 1, $valid ? 1 : 0);
                    }
                }
            }
            foreach ($scope(StaffAdvance::query())->with('repayments')->whereIn('status', ['confirmed', 'reversed'])->get() as $advance) {
                $expected = $advance->status === 'confirmed' ? Money::minor($advance->principal_amount) : 0;
                foreach ($advance->repayments->where('status', 'confirmed') as $repayment) {
                    $expected -= Money::minor($repayment->amount);
                }
                foreach ($payroll->where('staff_advance_id', $advance->id)->where('state', 'confirmed') as $recovery) {
                    $expected -= Money::minor($recovery->amount);
                }
                $actual = 0;
                foreach ($loanEntries->get($advance->id, collect()) as $entry) {
                    $actual += ($entry->effect === 'addition' ? 1 : -1) * Money::minor($entry->amount);
                    if ($entry->payroll_recovery_id) {
                        $recovery = $payroll->get($entry->payroll_recovery_id);
                        $valid = $recovery && ! $entry->cashbook_transaction_id && (int) $recovery->staff_advance_id === (int) $advance->id
                            && in_array($recovery->state, ['confirmed', 'reversed'], true) && $recovery->verified_by_id && $recovery->evidence_reference;
                        $add('payroll_evidence_link', 'staff_advance_entry', $entry->id, 1, $valid ? 1 : 0, $advance->currency_code);
                    } else {
                        $cash = $cashTransactions->get($entry->cashbook_transaction_id);
                        $add('advance_cash_link', 'staff_advance_entry', $entry->id, Money::minor($entry->amount), $cash ? Money::minor($cash->amount) : -1, $advance->currency_code);
                        $directions = ['loan_disbursement' => 'out', 'loan_reversal' => 'in', 'repayment' => 'in', 'repayment_reversal' => 'out'];
                        $cashBook = $cash ? $books->get($cash->cashbook_id) : null;
                        $valid = $cash && $cashBook && $cashBook->currency_code === $advance->currency_code
                            && (int) $cashBook->branch_id === (int) $advance->branch_id
                            && $cash->direction === ($directions[$entry->entry_type] ?? null)
                            && in_array($cash->status, ['confirmed', 'reversed'], true);
                        $add('advance_cash_ownership', 'staff_advance_entry', $entry->id, 1, $valid ? 1 : 0, $advance->currency_code);
                    }
                }
                $add('advance_outstanding', 'staff_advance', $advance->id, $expected, $actual, $advance->currency_code);
            }
            foreach ($scope(Depreciation::query())->with('scheduleLines')->get() as $depreciation) {
                $posted = 0;
                foreach ($depreciation->scheduleLines->where('status', 'posted') as $line) {
                    $posted += Money::minor($line->amount);
                    $cash = $cashTransactions->get($line->cashbook_transaction_id);
                    $add('depreciation_cash_link', 'depreciation_line', $line->id, Money::minor($line->amount), $cash && $cash->status === 'confirmed' ? Money::minor($cash->amount) : -1, $depreciation->currency_code);
                }
                $add('depreciation_posted_total', 'depreciation', $depreciation->id, $posted, Money::minor($depreciation->total_posted_amount), $depreciation->currency_code);
                $add('depreciation_cap', 'depreciation', $depreciation->id, min($posted, Money::minor($depreciation->asset_price)), $posted, $depreciation->currency_code);
            }
            foreach ($scope(Sale::query())->with('receivableEntries')->whereIn('status', ['confirmed', 'cancelled'])->get() as $sale) {
                $actual = 0;
                foreach ($sale->receivableEntries as $entry) {
                    $actual += Money::minor($entry->amount);
                }
                $expected = $sale->status === 'confirmed' ? Money::minor($sale->ar_amount) : 0;
                $add('sale_receivable', 'sale', $sale->id, $expected, $actual);
                $cashNet = 0;
                foreach ($cashTransactions->where('source_type', 'sale_receipt')->where('status', 'confirmed')->filter(fn ($cash) => $cash->idempotency_key === 'sale-'.$sale->id || str_starts_with($cash->idempotency_key ?? '', 'sale-receipt-'.$sale->id.'-')) as $cash) {
                    $cashNet += Money::minor($cash->amount);
                }
                $add('sale_cash_received', 'sale', $sale->id, $sale->status === 'confirmed' ? Money::minor($sale->paid_amount) : 0, $cashNet);
            }
            foreach ($scope(PurchasePayment::query())->whereIn('state', ['confirmed', 'reversed'])->get() as $payment) {
                $cash = $cashTransactions->get($payment->cashbook_transaction_id);
                $valid = $cash && $cash->source_type === 'purchase_payment' && $cash->direction === 'out'
                    && $cash->status === $payment->state && (int) $cash->cashbook_id === (int) $payment->cashbook_id
                    && Money::minor($cash->amount) === Money::minor($payment->amount);
                $add('purchase_payment_cash_link', 'purchase_payment', $payment->id, 1, $valid ? 1 : 0, $payment->currency_code);
            }
            foreach ($scope(CashbookTransfer::query())->whereIn('state', ['confirmed', 'reversed'])->get() as $transfer) {
                foreach (['out' => $transfer->out_transaction_id, 'in' => $transfer->in_transaction_id] as $direction => $id) {
                    $cash = $cashTransactions->get($id);
                    $valid = $cash && $cash->source_type === 'cashbook_transfer' && $cash->direction === $direction
                        && (int) $cash->cashbook_id === (int) ($direction === 'out' ? $transfer->source_cashbook_id : $transfer->destination_cashbook_id)
                        && $cash->status === $transfer->state && Money::minor($cash->amount) === Money::minor($transfer->amount);
                    $add('transfer_'.$direction.'_link', 'cashbook_transfer', $transfer->id, 1, $valid ? 1 : 0, $transfer->currency_code);
                }
            }

            return ['generated_at' => now()->toISOString(), 'passed' => ! collect($checks)->contains('passed', false), 'checks' => $checks];
        });
    }
}
