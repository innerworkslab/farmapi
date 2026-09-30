<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffAdvanceLedgerEntry;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffAdvanceService
{
    public function __construct(
        private readonly CashbookService $cashbooks,
        private readonly CashLedgerCategoryService $categories,
        private readonly CashbookTransactionService $cashbookTransactions,
        private readonly StaffAdvanceLedgerService $ledger
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = StaffAdvance::query()->with(['staff', 'branch', 'cashbook', 'category', 'cashbookTransaction']);
        $this->scopeToActorBranches($query, $actor);
        $query->when($filters['staff_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('staff_id', $id))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('branch_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['from_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $search = trim($search);
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('staff_code_snapshot', 'like', "%{$search}%")
                        ->orWhere('staff_name_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('staff', function (Builder $staff) use ($search): void {
                            $staff->where('staff_code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            });

        $paginator = $query->orderByDesc('business_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
        $paginator->getCollection()->each(fn (StaffAdvance $advance) => $this->setOutstanding($advance));

        return $paginator;
    }

    /** @param array<string, mixed> $filters */
    public function balances(array $filters, User $actor): LengthAwarePaginator
    {
        $additions = StaffAdvanceLedgerEntry::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN effect = 'addition' THEN amount ELSE 0 END), 0)")
            ->whereColumn('staff_advance_ledger_entries.staff_id', 'staff.id');
        $deductions = StaffAdvanceLedgerEntry::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN effect = 'deduction' THEN amount ELSE 0 END), 0)")
            ->whereColumn('staff_advance_ledger_entries.staff_id', 'staff.id');
        $outstanding = StaffAdvanceLedgerEntry::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN effect = 'addition' THEN amount ELSE -amount END), 0)")
            ->whereColumn('staff_advance_ledger_entries.staff_id', 'staff.id');
        $currency = StaffAdvanceLedgerEntry::query()->select('currency_code')
            ->whereColumn('staff_advance_ledger_entries.staff_id', 'staff.id')->orderByDesc('id')->limit(1);

        $query = Staff::query()->with('branch')->select('staff.*')
            ->selectSub($additions, 'total_additions')
            ->selectSub($deductions, 'total_deductions')
            ->selectSub($outstanding, 'outstanding_balance');
        $query->selectSub($currency, 'currency_code');
        $this->scopeToActorBranches($query, $actor);
        $query->when($filters['branch_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('branch_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['employment_status'] ?? null, fn (Builder $q, string $status) => $q->where('employment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $search = trim($search);
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('staff_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            });

        if (($filters['balance_state'] ?? null) === 'positive') {
            $query->whereRaw("({$outstanding->toSql()}) > 0", $outstanding->getBindings());
        } elseif (($filters['balance_state'] ?? null) === 'zero') {
            $query->whereRaw("({$outstanding->toSql()}) = 0", $outstanding->getBindings());
        }

        return $query->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 30));
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, User $actor): StaffAdvance
    {
        return DB::transaction(function () use ($data, $actor): StaffAdvance {
            $existing = StaffAdvance::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->staff_id !== (int) $data['staff_id'] ||
                    (int) $existing->cashbook_id !== (int) $data['cashbook_id'] ||
                    (int) $existing->category_id !== (int) $data['category_id'] ||
                    $this->ledger->toMinor((string) $existing->principal_amount) !== $this->ledger->toMinor((string) $data['principal_amount']) ||
                    $existing->business_date->toDateString() !== $data['business_date'] ||
                    $existing->description !== trim($data['description'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different staff advance data.']);
                }

                return $this->find($existing, $actor);
            }

            $staff = Staff::query()->lockForUpdate()->findOrFail($data['staff_id']);
            $branch = $this->assertStaffEligible($staff, $actor);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($data['cashbook_id']);
            $this->assertCashbookMatchesBranch($cashbook, (int) $branch->id, $actor);
            $this->categories->findActiveForDirection((int) $data['category_id'], 'out');
            $amount = $this->ledger->formatMinor($this->ledger->toMinor((string) $data['principal_amount']));

            $advance = StaffAdvance::query()->create([
                'staff_id' => $staff->id,
                'branch_id' => $branch->id,
                'reference' => $this->newReference('ADV'),
                'idempotency_key' => $data['idempotency_key'],
                'principal_amount' => $amount,
                'currency_code' => $cashbook->currency_code,
                'business_date' => $data['business_date'],
                'cashbook_id' => $cashbook->id,
                'category_id' => $data['category_id'],
                'description' => trim($data['description']),
                'status' => StaffAdvance::STATUS_DRAFT,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            return $this->find($advance, $actor);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(StaffAdvance $advance, array $data, User $actor): StaffAdvance
    {
        return DB::transaction(function () use ($advance, $data, $actor): StaffAdvance {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->assertAdvanceAccess($advance, $actor);
            if ($advance->status !== StaffAdvance::STATUS_DRAFT) {
                throw ValidationException::withMessages(['staff_advance' => 'Only draft advances can be edited.']);
            }

            $staff = Staff::query()->lockForUpdate()->findOrFail($data['staff_id'] ?? $advance->staff_id);
            $branch = $this->assertStaffEligible($staff, $actor);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($data['cashbook_id'] ?? $advance->cashbook_id);
            $this->assertCashbookMatchesBranch($cashbook, (int) $branch->id, $actor);
            $categoryId = (int) ($data['category_id'] ?? $advance->category_id);
            $this->categories->findActiveForDirection($categoryId, 'out');

            if (isset($data['principal_amount'])) {
                $advance->principal_amount = $this->ledger->formatMinor($this->ledger->toMinor((string) $data['principal_amount']));
            }
            if (isset($data['business_date'])) {
                $advance->business_date = $data['business_date'];
            }
            if (isset($data['description'])) {
                $advance->description = trim($data['description']);
            }
            $advance->staff_id = $staff->id;
            $advance->branch_id = $branch->id;
            $advance->cashbook_id = $cashbook->id;
            $advance->currency_code = $cashbook->currency_code;
            $advance->category_id = $categoryId;
            $advance->version++;
            $advance->save();

            return $this->find($advance->refresh(), $actor);
        });
    }

    public function find(StaffAdvance $advance, User $actor): StaffAdvance
    {
        $advance = StaffAdvance::query()->with(['staff', 'branch', 'cashbook', 'category', 'cashbookTransaction', 'repayments'])
            ->findOrFail($advance->id);
        $this->assertAdvanceAccess($advance, $actor);
        $this->setOutstanding($advance);

        return $advance;
    }

    public function confirm(StaffAdvance $advance, User $actor): StaffAdvance
    {
        return DB::transaction(function () use ($advance, $actor): StaffAdvance {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->assertAdvanceAccess($advance, $actor);
            if ($advance->status === StaffAdvance::STATUS_CONFIRMED) {
                return $this->find($advance, $actor);
            }
            if ($advance->status !== StaffAdvance::STATUS_DRAFT) {
                throw ValidationException::withMessages(['staff_advance' => 'Only draft advances can be confirmed.']);
            }

            $staff = Staff::query()->lockForUpdate()->findOrFail($advance->staff_id);
            $branch = $this->assertStaffEligible($staff, $actor);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($advance->cashbook_id);
            $this->assertCashbookMatchesBranch($cashbook, (int) $branch->id, $actor);
            $this->assertStaffCurrency((int) $staff->id, $cashbook->currency_code);
            $category = $this->categories->findActiveForDirection((int) $advance->category_id, 'out');
            $this->assertBusinessDate($advance->business_date->toDateString(), $cashbook);

            $cashbookTransaction = $this->cashbookTransactions->postForModule([
                'cashbook_id' => $cashbook->id,
                'category_id' => $category->id,
                'direction' => 'out',
                'amount' => $advance->principal_amount,
                'business_date' => $advance->business_date->toDateString(),
                'description' => 'Staff loan disbursement: '.$advance->description,
                'external_reference' => $advance->reference,
                'idempotency_key' => 'SA-DISBURSE-'.$advance->reference,
            ], 'staff_advance_disbursement', $actor);

            $advance->branch_id = $branch->id;
            $advance->staff_code_snapshot = $staff->staff_code;
            $advance->staff_name_snapshot = $staff->name;
            $advance->branch_code_snapshot = $branch->code;
            $advance->branch_name_snapshot = $branch->name;
            $advance->cashbook_transaction_id = $cashbookTransaction->id;
            $advance->status = StaffAdvance::STATUS_CONFIRMED;
            $advance->confirmed_by_id = $actor->id;
            $advance->confirmed_at = now();
            $advance->version++;
            $advance->save();

            $this->ledger->record($staff, $advance, null, $cashbookTransaction, null,
                $advance->reference, $advance->business_date->toDateString(), 'loan_disbursement', 'addition',
                $advance->principal_amount, $advance->description, $actor);

            return $this->find($advance->refresh(), $actor);
        });
    }

    public function reverse(StaffAdvance $advance, string $reason, User $actor): StaffAdvance
    {
        return DB::transaction(function () use ($advance, $reason, $actor): StaffAdvance {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->assertAdvanceAccess($advance, $actor);
            if ($advance->status !== StaffAdvance::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['staff_advance' => 'Only confirmed advances can be reversed.']);
            }
            if ($advance->repayments()->whereIn('status', [StaffAdvanceRepayment::STATUS_DRAFT, StaffAdvanceRepayment::STATUS_CONFIRMED])->exists()) {
                throw ValidationException::withMessages(['staff_advance' => 'Cancel draft repayments or reverse confirmed repayments first. This loan has dependent repayment activity.']);
            }

            $staff = Staff::withTrashed()->lockForUpdate()->findOrFail($advance->staff_id);
            $cashbookTransaction = CashbookTransaction::query()->findOrFail($advance->cashbook_transaction_id);
            $reversal = $this->cashbookTransactions->reverseForModule($cashbookTransaction, 'staff_advance_disbursement', $reason, $actor);
            $originalEntry = StaffAdvanceLedgerEntry::query()->where('staff_advance_id', $advance->id)
                ->where('entry_type', 'loan_disbursement')->firstOrFail();

            $this->ledger->record($staff, $advance, null, $reversal, $originalEntry, $reversal->reference,
                $reversal->business_date->toDateString(), 'loan_reversal', 'deduction', $advance->principal_amount,
                'Reversal of '.$advance->reference.': '.trim($reason), $actor);

            $advance->status = StaffAdvance::STATUS_REVERSED;
            $advance->reversed_by_id = $actor->id;
            $advance->reversed_at = now();
            $advance->reversal_reason = trim($reason);
            $advance->version++;
            $advance->save();

            return $this->find($advance->refresh(), $actor);
        });
    }

    /** @param array<string, mixed> $filters */
    public function history(Staff $staff, array $filters, User $actor): array
    {
        $staff = Staff::query()->with('branch')->findOrFail($staff->id);
        $this->assertBranchAccess(Branch::withTrashed()->find($staff->branch_id), $actor, false);
        $query = StaffAdvanceLedgerEntry::query()->where('staff_id', $staff->id);
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
        $query->when($filters['from_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '<=', $date))
            ->when($filters['entry_type'] ?? null, fn (Builder $q, string $type) => $q->where('entry_type', $type));

        return [
            'entries' => $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 30)),
            'current_balance' => $this->ledger->formatMinor($this->ledger->staffBalanceMinor((int) $staff->id)),
            'currency_code' => StaffAdvanceLedgerEntry::query()->where('staff_id', $staff->id)->orderByDesc('id')->value('currency_code'),
        ];
    }

    public function currentBalanceForStaff(Staff $staff, User $actor): string
    {
        $staff = Staff::query()->findOrFail($staff->id);
        $this->assertBranchAccess(Branch::withTrashed()->find($staff->branch_id), $actor, false);

        return $this->ledger->formatMinor($this->ledger->staffBalanceMinor((int) $staff->id));
    }

    private function setOutstanding(StaffAdvance $advance): void
    {
        $balance = $advance->status === StaffAdvance::STATUS_CONFIRMED
            ? $this->ledger->loanBalanceMinor((int) $advance->id)
            : 0;
        $advance->setAttribute('outstanding_amount', $this->ledger->formatMinor($balance));
    }

    private function assertAdvanceAccess(StaffAdvance $advance, User $actor): void
    {
        $branch = Branch::withTrashed()->find($advance->branch_id);
        $this->assertBranchAccess($branch, $actor, false);
    }

    private function assertStaffEligible(Staff $staff, User $actor): Branch
    {
        $branch = Branch::query()->findOrFail($staff->branch_id);
        $this->assertBranchAccess($branch, $actor, true);
        if (! $staff->isEligibleForAdvance()) {
            throw ValidationException::withMessages(['staff_id' => 'Only active, employed staff may receive a new staff loan.']);
        }

        return $branch;
    }

    private function assertCashbookMatchesBranch(Cashbook $cashbook, int $branchId, User $actor): void
    {
        $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
        if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook is required.']);
        }
        if ((int) $cashbook->branch_id !== $branchId) {
            throw ValidationException::withMessages(['cashbook_id' => 'The cashbook must belong to the staff member\'s branch.']);
        }
    }

    private function assertStaffCurrency(int $staffId, string $currencyCode): void
    {
        $existingCurrency = StaffAdvanceLedgerEntry::query()->where('staff_id', $staffId)->orderBy('id')->value('currency_code');
        if ($existingCurrency !== null && $existingCurrency !== $currencyCode) {
            throw ValidationException::withMessages(['cashbook_id' => "Staff advances for this staff member use {$existingCurrency}; a cashbook in {$currencyCode} cannot be used."]);
        }
    }

    private function assertBusinessDate(string $date, Cashbook $cashbook): void
    {
        $businessDate = CarbonImmutable::parse($date);
        if ($businessDate->isFuture()) {
            throw ValidationException::withMessages(['business_date' => 'Future-dated transactions cannot be posted.']);
        }
        if ($businessDate->lt($cashbook->effective_date)) {
            throw ValidationException::withMessages(['business_date' => 'Transaction date cannot be before the cashbook effective date.']);
        }
    }

    private function assertBranchAccess(?Branch $branch, User $actor, bool $mustBeActive): void
    {
        if (! $branch || ($mustBeActive && ($branch->trashed() || $branch->status !== Branch::STATUS_ACTIVE))) {
            throw ValidationException::withMessages(['branch_id' => 'An active branch is required.']);
        }
        if (! $actor->hasRole('super-admin') && ! $actor->branches()->whereKey($branch->id)->exists()) {
            abort(403, 'You are not authorized to access this branch.');
        }
    }

    private function scopeToActorBranches(Builder $query, User $actor): void
    {
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
    }

    private function newReference(string $prefix): string
    {
        return $prefix.'-'.Str::upper((string) Str::ulid());
    }
}
