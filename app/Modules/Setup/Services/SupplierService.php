<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Financial\Support\Money;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\SupplierPayableEntry;
use App\Modules\Setup\Repositories\SupplierRepository;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService extends BusinessMasterService
{
    public function __construct(SupplierRepository $repository)
    {
        parent::__construct($repository);
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $paginator = parent::paginate($filters, $actor);
        $suppliers = $paginator->getCollection();
        if ($suppliers->isEmpty()) {
            return $paginator;
        }

        $supplierIds = $suppliers->modelKeys();
        $otherEntries = SupplierPayableEntry::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereRaw('SUBSTRING(entry_type, 1, 8) <> ?', ['invoice_'])
            ->selectRaw('supplier_id, SUM(amount) as amount')
            ->groupBy('supplier_id')
            ->pluck('amount', 'supplier_id');

        $confirmedPayments = PurchasePayment::query()
            ->where('state', 'confirmed')
            ->select('purchase_invoice_id')
            ->selectRaw('SUM(amount) as paid_amount')
            ->groupBy('purchase_invoice_id');
        $invoicePayables = PurchaseInvoice::query()
            ->leftJoinSub($confirmedPayments, 'confirmed_payments', fn ($join) => $join->on('confirmed_payments.purchase_invoice_id', '=', 'purchase_invoices.id'))
            ->whereIn('purchase_invoices.supplier_id', $supplierIds)
            ->whereIn('purchase_invoices.status', [PurchaseInvoice::STATUS_OPEN, PurchaseInvoice::STATUS_CONFIRMED])
            ->selectRaw('purchase_invoices.supplier_id, SUM(purchase_invoices.total_amount - COALESCE(confirmed_payments.paid_amount, 0)) as amount')
            ->groupBy('purchase_invoices.supplier_id')
            ->pluck('amount', 'supplier_id');

        $suppliers->each(function (Supplier $supplier) use ($otherEntries, $invoicePayables): void {
            $netMinor = Money::minor((string) ($otherEntries[$supplier->id] ?? '0'))
                + Money::minor((string) ($invoicePayables[$supplier->id] ?? '0'));
            $totalCreditMinor = max($netMinor, 0);
            $availableCreditMinor = max(Money::minor((string) $supplier->credit_limit) - $totalCreditMinor, 0);

            $supplier->setAttribute('total_credit', Money::decimal($totalCreditMinor));
            $supplier->setAttribute('available_credit', Money::decimal($availableCreditMinor));
        });

        return $paginator;
    }

    public function createWithOpeningBalance(array $data, User $actor): Supplier
    {
        return DB::transaction(function () use ($data, $actor): Supplier {
            $types = array_values(array_unique($data['types'] ?? [$data['type']]));
            unset($data['types'], $data['opening_balance_date']);
            $amount = (float) ($data['opening_balance'] ?? 0);
            $data['opening_balance_type'] = $amount > 0 ? 'credit' : 'none';
            if ($amount > 0) {
                $data['opening_balance_date'] = today();
            }
            if ($amount > 0 && empty($data['preferred_branch_id'])) {
                throw ValidationException::withMessages(['preferred_branch_id' => 'A branch is required to post a supplier opening payable.']);
            }
            if ($amount > 0 && ($data['opening_balance_type'] ?? null) === 'none') {
                throw ValidationException::withMessages(['opening_balance_type' => 'Choose debit or credit to post a non-zero opening payable.']);
            }
            if ($amount > 0) {
                BranchAccess::authorize($actor, (int) $data['preferred_branch_id']);
            }
            $codeBranch = Branch::query()->findOrFail($data['preferred_branch_id']);
            BranchAccess::authorize($actor, (int) $codeBranch->id);
            $data['code'] = app(BranchMasterCodeGenerator::class)->next($codeBranch, 'suppliers', 'SUP');
            $supplier = $this->create($data);
            $supplier->types()->createMany(array_map(fn (string $type): array => ['type' => $type], $types));
            activity('setup')->performedOn($supplier)->causedBy($actor)
                ->withProperties(['types' => $types])
                ->log('Supplier types assigned');
            if ($amount > 0 && ($data['opening_balance_type'] ?? 'credit') !== 'none') {
                SupplierPayableEntry::query()->create([
                    'supplier_id' => $supplier->id, 'branch_id' => $data['preferred_branch_id'],
                    'entry_type' => 'opening_balance',
                    'amount' => ($data['opening_balance_type'] ?? 'credit') === 'debit' ? -$amount : $amount,
                    'currency_code' => $data['opening_balance_currency_code'],
                    'business_date' => today(), 'reference' => 'SUPPLIER-OPENING-'.$supplier->id,
                    'idempotency_key' => 'supplier-'.$supplier->id.'-opening-balance', 'created_by_id' => $actor->id,
                ]);
                activity('setup')->performedOn($supplier)->causedBy($actor)
                    ->withProperties(['amount' => $amount, 'currency_code' => $data['opening_balance_currency_code'], 'branch_id' => $data['preferred_branch_id'], 'business_date' => today()->toDateString()])
                    ->log('Supplier opening payable posted');
            }

            return $supplier->load(['preferredBranch', 'types']);
        });
    }

    public function updateWithTypes(Supplier $supplier, array $data, User $actor): Supplier
    {
        $types = $data['types'] ?? null;
        unset($data['types'], $data['opening_balance_date'], $data['code']);
        if ($supplier->payableEntries()->exists() && (
            (isset($data['opening_balance']) && (float) $data['opening_balance'] !== (float) $supplier->opening_balance)
            || (isset($data['opening_balance_type']) && $data['opening_balance_type'] !== $supplier->opening_balance_type)
        )) {
            throw ValidationException::withMessages(['opening_balance' => 'Posted opening payables are immutable. Record a dated payable adjustment instead.']);
        }
        if ($types !== null) {
            $oldTypes = $supplier->types()->pluck('type')->all();
            $supplier->types()->delete();
            $supplier->types()->createMany(array_map(fn (string $type): array => ['type' => $type], array_values(array_unique($types))));
            if ($oldTypes !== array_values(array_unique($types))) {
                activity('setup')->performedOn($supplier)->causedBy($actor)
                    ->withProperties(['old_types' => $oldTypes, 'new_types' => array_values(array_unique($types))])
                    ->log('Supplier types updated');
            }
        }

        return $this->update($supplier, $data)->load(['preferredBranch', 'types']);
    }

    public function payableSummary(Supplier $supplier, User $actor): array
    {
        $entries = BranchAccess::scope(SupplierPayableEntry::query()->where('supplier_id', $supplier->id), $actor)->orderBy('business_date')->get();
        $invoices = BranchAccess::scope(PurchaseInvoice::query(), $actor)->where('supplier_id', $supplier->id)->whereIn('status', [PurchaseInvoice::STATUS_OPEN, PurchaseInvoice::STATUS_CONFIRMED])->get();
        $payments = $invoices->isEmpty() ? collect() : PurchasePayment::query()->whereIn('purchase_invoice_id', $invoices->pluck('id'))->where('state', 'confirmed')->get();
        $balances = [];
        $bucket = function (?string $currency) use (&$balances): array {
            $key = $currency ?? 'UNSPECIFIED';

            return $balances[$key] ??= ['currency_code' => $currency, 'opening_balance' => 0, 'invoice_amount' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0];
        };
        foreach ($entries as $entry) {
            if (str_starts_with($entry->entry_type, 'invoice_')) {
                continue;
            }
            $key = $entry->currency_code ?? 'UNSPECIFIED';
            $balances[$key] = $bucket($entry->currency_code);
            $balances[$key]['opening_balance'] += Money::minor($entry->amount);
        }
        foreach ($invoices as $invoice) {
            $key = $invoice->currency_code ?? 'UNSPECIFIED';
            $balances[$key] = $bucket($invoice->currency_code);
            $balances[$key]['invoice_amount'] += Money::minor($invoice->total_amount);
            $balances[$key]['outstanding_amount'] += Money::minor($invoice->total_amount);
        }
        foreach ($payments as $payment) {
            $key = $payment->currency_code ?? 'UNSPECIFIED';
            $balances[$key] = $bucket($payment->currency_code);
            $minor = Money::minor($payment->amount);
            $balances[$key]['paid_amount'] += $minor;
            $balances[$key]['outstanding_amount'] -= $minor;
        }
        $balances = array_values(array_map(fn (array $balance): array => [
            'currency_code' => $balance['currency_code'],
            'opening_balance' => Money::decimal($balance['opening_balance']),
            'invoice_amount' => Money::decimal($balance['invoice_amount']),
            'paid_amount' => Money::decimal($balance['paid_amount']),
            'outstanding_amount' => Money::decimal($balance['opening_balance'] + $balance['outstanding_amount']),
        ], $balances));

        return [
            'supplier_id' => $supplier->id,
            'entries' => $entries->map(fn (SupplierPayableEntry $entry): array => [
                'id' => $entry->id, 'branch_id' => $entry->branch_id, 'entry_type' => $entry->entry_type,
                'purchase_invoice_id' => preg_match('/^purchase-invoice-(\d+)-/', $entry->idempotency_key ?? '', $match) ? (int) $match[1] : null,
                'amount' => $entry->amount, 'currency_code' => $entry->currency_code, 'business_date' => $entry->business_date->toDateString(), 'reference' => $entry->reference,
            ])->all(),
            'balances' => $balances,
        ];
    }

    public function adjustPayable(Supplier $supplier, array $data, User $actor): SupplierPayableEntry
    {
        return DB::transaction(function () use ($supplier, $data, $actor): SupplierPayableEntry {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            BranchAccess::authorize($actor, (int) $data['branch_id']);
            $amountMinor = Money::minor((string) $data['amount']);
            $reference = mb_substr($data['reason'], 0, 100);

            $existing = SupplierPayableEntry::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->supplier_id !== (int) $supplier->id
                    || (int) $existing->branch_id !== (int) $data['branch_id']
                    || $existing->currency_code !== 'MMK'
                    || Money::minor($existing->amount) !== -$amountMinor
                    || $existing->reference !== $reference) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was used for a different payable adjustment.']);
                }

                return $existing;
            }

            $entries = SupplierPayableEntry::query()
                ->where('supplier_id', $supplier->id)
                ->where('branch_id', $data['branch_id'])
                ->whereRaw('SUBSTRING(entry_type, 1, 8) <> ?', ['invoice_'])
                ->lockForUpdate()
                ->get();
            $invoices = PurchaseInvoice::query()
                ->where('supplier_id', $supplier->id)
                ->where('branch_id', $data['branch_id'])
                ->whereIn('status', [PurchaseInvoice::STATUS_OPEN, PurchaseInvoice::STATUS_CONFIRMED])
                ->lockForUpdate()
                ->get(['id', 'total_amount']);
            $paidByInvoice = $invoices->isEmpty()
                ? collect()
                : PurchasePayment::query()
                    ->whereIn('purchase_invoice_id', $invoices->pluck('id'))
                    ->where('state', 'confirmed')
                    ->get(['purchase_invoice_id', 'amount'])
                    ->groupBy('purchase_invoice_id')
                    ->map(fn ($payments) => $payments->sum(fn (PurchasePayment $payment): int => Money::minor($payment->amount)));

            $currentPayable = $entries->sum(fn (SupplierPayableEntry $entry): int => Money::minor($entry->amount));
            foreach ($invoices as $invoice) {
                $currentPayable += Money::minor($invoice->total_amount) - (int) ($paidByInvoice[$invoice->id] ?? 0);
            }

            if ($currentPayable < $amountMinor) {
                throw ValidationException::withMessages(['amount' => 'Adjustment cannot exceed the current payable balance for this supplier and branch.']);
            }

            $entry = SupplierPayableEntry::query()->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $data['branch_id'],
                'entry_type' => 'adjustment',
                'amount' => Money::decimal(-$amountMinor),
                'currency_code' => 'MMK',
                'business_date' => today(),
                'reference' => $reference,
                'idempotency_key' => $data['idempotency_key'],
                'created_by_id' => $actor->id,
            ]);
            activity('setup')->performedOn($supplier)->causedBy($actor)
                ->withProperties(['entry_id' => $entry->id, 'amount' => $entry->amount, 'settled_amount' => Money::decimal($amountMinor), 'currency_code' => 'MMK', 'branch_id' => $entry->branch_id, 'reason' => $data['reason']])
                ->log('Supplier payable settled by adjustment');

            return $entry;
        });
    }
}
