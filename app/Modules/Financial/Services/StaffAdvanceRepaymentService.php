<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffAdvanceLedgerEntry;
use App\Modules\Financial\Models\StaffAdvanceRepayment;
use App\Modules\Setup\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffAdvanceRepaymentService
{
    public function __construct(
        private readonly StaffAdvanceService $advances,
        private readonly CashbookService $cashbooks,
        private readonly CashLedgerCategoryService $categories,
        private readonly CashbookTransactionService $cashbookTransactions,
        private readonly StaffAdvanceLedgerService $ledger
    ) {}

    public function paginate(StaffAdvance $advance, User $actor, int $perPage = 15): LengthAwarePaginator
    {
        $advance = $this->advances->find($advance, $actor);

        return $advance->repayments()->with(['advance', 'cashbook', 'category', 'cashbookTransaction'])
            ->orderByDesc('business_date')->orderByDesc('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function createDraft(StaffAdvance $advance, array $data, User $actor): StaffAdvanceRepayment
    {
        return DB::transaction(function () use ($advance, $data, $actor): StaffAdvanceRepayment {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->advances->find($advance, $actor);
            if ($advance->status !== StaffAdvance::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['staff_advance_id' => 'Repayments can only be made against a confirmed loan.']);
            }
            $amount = $this->ledger->formatMinor($this->ledger->toMinor((string) $data['amount']));

            $existing = StaffAdvanceRepayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->staff_advance_id !== (int) $advance->id ||
                    (int) $existing->cashbook_id !== (int) $data['cashbook_id'] ||
                    (int) $existing->category_id !== (int) $data['category_id'] ||
                    $this->ledger->toMinor((string) $existing->amount) !== $this->ledger->toMinor($amount) ||
                    $existing->business_date->toDateString() !== $data['business_date'] ||
                    $existing->description !== trim($data['description']) ||
                    $existing->external_reference !== ($data['external_reference'] ?? null)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different repayment data.']);
                }

                return $this->find($existing, $actor);
            }

            $amountMinor = $this->ledger->toMinor($amount);
            if ($amountMinor > $this->ledger->loanBalanceMinor((int) $advance->id)) {
                throw ValidationException::withMessages(['amount' => 'Repayment cannot exceed the loan amount still owed.']);
            }

            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($data['cashbook_id']);
            $this->assertCashbookMatchesLoanBranch($cashbook, (int) $advance->branch_id, (string) $advance->currency_code, $actor);
            $this->categories->findActiveForDirection((int) $data['category_id'], 'in');

            $repayment = StaffAdvanceRepayment::query()->create([
                'staff_advance_id' => $advance->id,
                'staff_id' => $advance->staff_id,
                'branch_id' => $advance->branch_id,
                'reference' => 'ADVR-'.Str::upper((string) Str::ulid()),
                'idempotency_key' => $data['idempotency_key'],
                'amount' => $amount,
                'business_date' => $data['business_date'],
                'cashbook_id' => $cashbook->id,
                'category_id' => $data['category_id'],
                'external_reference' => $data['external_reference'] ?? null,
                'description' => trim($data['description']),
                'status' => StaffAdvanceRepayment::STATUS_DRAFT,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            return $this->find($repayment, $actor);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(StaffAdvanceRepayment $repayment, array $data, User $actor): StaffAdvanceRepayment
    {
        return DB::transaction(function () use ($repayment, $data, $actor): StaffAdvanceRepayment {
            $repayment = StaffAdvanceRepayment::query()->lockForUpdate()->findOrFail($repayment->id);
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($repayment->staff_advance_id);
            $this->advances->find($advance, $actor);
            if ($repayment->status !== StaffAdvanceRepayment::STATUS_DRAFT || $advance->status !== StaffAdvance::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['repayment' => 'Only repayment drafts for an open loan can be edited.']);
            }

            $cashbookId = (int) ($data['cashbook_id'] ?? $repayment->cashbook_id);
            $categoryId = (int) ($data['category_id'] ?? $repayment->category_id);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($cashbookId);
            $this->assertCashbookMatchesLoanBranch($cashbook, (int) $advance->branch_id, (string) $advance->currency_code, $actor);
            $this->categories->findActiveForDirection($categoryId, 'in');

            foreach (['amount', 'business_date', 'external_reference', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $repayment->{$field} = $data[$field];
                }
            }
            if (isset($data['amount'])) {
                $repayment->amount = $this->ledger->formatMinor($this->ledger->toMinor((string) $data['amount']));
            }
            if (array_key_exists('external_reference', $data)) {
                $repayment->external_reference = $this->normalizeReference($data['external_reference']);
            }
            $repayment->cashbook_id = $cashbookId;
            $repayment->category_id = $categoryId;
            $repayment->version++;
            $repayment->save();

            return $this->find($repayment->refresh(), $actor);
        });
    }

    public function find(StaffAdvanceRepayment $repayment, User $actor): StaffAdvanceRepayment
    {
        $repayment = StaffAdvanceRepayment::query()->with(['advance', 'staff', 'branch', 'cashbook', 'category', 'cashbookTransaction'])
            ->findOrFail($repayment->id);
        $this->advances->find($repayment->advance, $actor);

        return $repayment;
    }

    public function confirm(StaffAdvanceRepayment $repayment, User $actor): StaffAdvanceRepayment
    {
        return DB::transaction(function () use ($repayment, $actor): StaffAdvanceRepayment {
            $repayment = StaffAdvanceRepayment::query()->lockForUpdate()->findOrFail($repayment->id);
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($repayment->staff_advance_id);
            $this->advances->find($advance, $actor);
            if ($repayment->status === StaffAdvanceRepayment::STATUS_CONFIRMED) {
                return $this->find($repayment, $actor);
            }
            if ($repayment->status !== StaffAdvanceRepayment::STATUS_DRAFT || $advance->status !== StaffAdvance::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['repayment' => 'Only a draft repayment for a confirmed loan can be posted.']);
            }

            $staff = Staff::withTrashed()->lockForUpdate()->findOrFail($repayment->staff_id);
            $amountMinor = $this->ledger->toMinor((string) $repayment->amount);
            $outstanding = $this->ledger->loanBalanceMinor((int) $advance->id);
            if ($amountMinor <= 0 || $amountMinor > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'Repayment must be positive and cannot exceed the loan amount still owed.']);
            }

            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($repayment->cashbook_id);
            $this->assertCashbookMatchesLoanBranch($cashbook, (int) $advance->branch_id, (string) $advance->currency_code, $actor);
            $category = $this->categories->findActiveForDirection((int) $repayment->category_id, 'in');

            $cashbookTransaction = $this->cashbookTransactions->postForModule([
                'cashbook_id' => $cashbook->id,
                'category_id' => $category->id,
                'direction' => 'in',
                'amount' => $repayment->amount,
                'business_date' => $repayment->business_date->toDateString(),
                'description' => 'Staff loan repayment: '.$repayment->description,
                'external_reference' => $repayment->reference,
                'idempotency_key' => 'SA-REPAY-'.$repayment->reference,
            ], 'staff_advance_repayment', $actor);

            $this->ledger->record($staff, $advance, $repayment, $cashbookTransaction, null,
                $repayment->reference, $repayment->business_date->toDateString(), 'repayment', 'deduction',
                $repayment->amount, $repayment->description, $actor);

            $repayment->cashbook_transaction_id = $cashbookTransaction->id;
            $repayment->status = StaffAdvanceRepayment::STATUS_CONFIRMED;
            $repayment->confirmed_by_id = $actor->id;
            $repayment->confirmed_at = now();
            $repayment->version++;
            $repayment->save();

            return $this->find($repayment->refresh(), $actor);
        });
    }

    public function reverse(StaffAdvanceRepayment $repayment, string $reason, User $actor): StaffAdvanceRepayment
    {
        return DB::transaction(function () use ($repayment, $reason, $actor): StaffAdvanceRepayment {
            $repayment = StaffAdvanceRepayment::query()->lockForUpdate()->findOrFail($repayment->id);
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($repayment->staff_advance_id);
            $this->advances->find($advance, $actor);
            if ($repayment->status !== StaffAdvanceRepayment::STATUS_CONFIRMED || $advance->status !== StaffAdvance::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['repayment' => 'Only confirmed repayments against an open loan can be reversed.']);
            }

            $staff = Staff::withTrashed()->lockForUpdate()->findOrFail($repayment->staff_id);
            $cashbookTransaction = CashbookTransaction::query()->findOrFail($repayment->cashbook_transaction_id);
            $reversal = $this->cashbookTransactions->reverseForModule($cashbookTransaction, 'staff_advance_repayment', $reason, $actor);
            $originalEntry = StaffAdvanceLedgerEntry::query()->where('staff_advance_repayment_id', $repayment->id)
                ->where('entry_type', 'repayment')->firstOrFail();

            $this->ledger->record($staff, $advance, $repayment, $reversal, $originalEntry, $reversal->reference,
                $reversal->business_date->toDateString(), 'repayment_reversal', 'addition', $repayment->amount,
                'Reversal of '.$repayment->reference.': '.trim($reason), $actor);

            $repayment->status = StaffAdvanceRepayment::STATUS_REVERSED;
            $repayment->reversed_by_id = $actor->id;
            $repayment->reversed_at = now();
            $repayment->reversal_reason = trim($reason);
            $repayment->version++;
            $repayment->save();

            return $this->find($repayment->refresh(), $actor);
        });
    }

    public function cancelDraft(StaffAdvanceRepayment $repayment, string $reason, User $actor): StaffAdvanceRepayment
    {
        return DB::transaction(function () use ($repayment, $reason, $actor): StaffAdvanceRepayment {
            $repayment = StaffAdvanceRepayment::query()->lockForUpdate()->findOrFail($repayment->id);
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($repayment->staff_advance_id);
            $this->advances->find($advance, $actor);
            if ($repayment->status !== StaffAdvanceRepayment::STATUS_DRAFT) {
                throw ValidationException::withMessages(['repayment' => 'Only draft repayments can be cancelled.']);
            }

            $repayment->status = StaffAdvanceRepayment::STATUS_CANCELLED;
            $repayment->cancelled_by_id = $actor->id;
            $repayment->cancelled_at = now();
            $repayment->cancellation_reason = trim($reason);
            $repayment->version++;
            $repayment->save();

            return $this->find($repayment->refresh(), $actor);
        });
    }

    private function assertCashbookMatchesLoanBranch(Cashbook $cashbook, int $branchId, string $currencyCode, User $actor): void
    {
        $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
        if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook is required.']);
        }
        if ((int) $cashbook->branch_id !== $branchId) {
            throw ValidationException::withMessages(['cashbook_id' => 'The cashbook must belong to the loan branch.']);
        }
        if ($cashbook->currency_code !== $currencyCode) {
            throw ValidationException::withMessages(['cashbook_id' => 'The repayment cashbook must use the same currency as the original loan.']);
        }
    }

    private function normalizeReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : $reference;
    }
}
