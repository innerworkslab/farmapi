<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\CashbookTransfer;
use App\Modules\Financial\Models\DepreciationScheduleLine;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Financial\Models\FinancialPeriod;
use App\Modules\Financial\Models\FinancialPostingLimit;
use App\Modules\Financial\Models\StaffPayrollRecovery;
use App\Modules\Financial\Support\Money;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Setup\Models\Branch;
use App\Support\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialControlService
{
    public function settings(int $branchId, User $actor): ?FinancialBranchSetting
    {
        BranchAccess::authorize($actor, $branchId);

        return FinancialBranchSetting::query()->where('branch_id', $branchId)->first();
    }

    public function periods(User $actor)
    {
        return BranchAccess::scope(FinancialPeriod::query(), $actor)->orderByDesc('from_date')->paginate(30);
    }

    public function limits(int $branchId, User $actor)
    {
        BranchAccess::authorize($actor, $branchId);

        return FinancialPostingLimit::query()->where('branch_id', $branchId)->orderBy('user_id')->get();
    }

    public function lockCashbook(int|string $id, User $actor): Cashbook
    {
        $book = Cashbook::query()->findOrFail($id);
        BranchAccess::authorize($actor, (int) $book->branch_id);
        Branch::query()->lockForUpdate()->findOrFail($book->branch_id);

        return Cashbook::query()->lockForUpdate()->findOrFail($id);
    }

    public function businessDate(int $branchId): string
    {
        $timezone = FinancialBranchSetting::query()->where('branch_id', $branchId)->value('timezone') ?? config('app.timezone');

        return CarbonImmutable::now($timezone)->toDateString();
    }

    // All cash movements and period changes serialize on the branch row, including module-owned postings.
    public function assertDate(int $branchId, string $date, User $actor, bool $checkBackdate = true): void
    {
        BranchAccess::authorize($actor, $branchId);
        Branch::query()->lockForUpdate()->findOrFail($branchId);
        $today = $this->businessDate($branchId);
        if ($date > $today) {
            throw ValidationException::withMessages(['business_date' => 'Future business dates cannot be posted.']);
        }
        if ($checkBackdate && $date < $today && ! $actor->hasRole('super-admin') && ! $actor->can('financial.transactions.backdate')) {
            throw ValidationException::withMessages(['business_date' => 'Backdated cash posting requires financial.transactions.backdate permission.']);
        }
        $period = FinancialPeriod::query()->where('branch_id', $branchId)->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->lockForUpdate()->first();
        if ($period?->state === 'closed') {
            throw ValidationException::withMessages(['business_date' => 'The financial period is closed. Reopen it through the authorized workflow first.']);
        }
        $settings = FinancialBranchSetting::query()->where('branch_id', $branchId)->lockForUpdate()->first();
        if (! $settings) {
            throw ValidationException::withMessages(['financial_settings' => 'Configure branch currency, timezone, period and approval policy before posting financial movements.']);
        }
        if ($settings?->require_open_period && ! $period) {
            throw ValidationException::withMessages(['business_date' => 'Create an open financial period covering this business date.']);
        }
    }

    public function assertPosting(CashbookTransaction $transaction, Cashbook $cashbook, User $actor): void
    {
        $this->assertDate((int) $cashbook->branch_id, $transaction->business_date->toDateString(), $actor);
        $this->assertApproval((int) $cashbook->branch_id, $cashbook->currency_code, $transaction->amount, (int) $transaction->created_by_id, $actor);
        app(FinancialReconciliationService::class)->assertCashbook($cashbook);
    }

    public function assertApproval(int $branchId, string $currency, string $amount, int $creatorId, User $actor): void
    {
        $settings = FinancialBranchSetting::query()->where('branch_id', $branchId)->lockForUpdate()->first();
        if (! $settings) {
            throw ValidationException::withMessages(['financial_settings' => 'Configure the branch financial policy before posting.']);
        }
        if ($settings && ! in_array($currency, $settings->enabled_currencies, true)) {
            throw ValidationException::withMessages(['currency_code' => 'Currency is not enabled for this branch.']);
        }
        $limit = FinancialPostingLimit::query()->where('branch_id', $branchId)->where('user_id', $actor->id)->where('currency_code', $currency)->lockForUpdate()->first();
        if ($settings?->require_approval_limit && ! $limit) {
            throw ValidationException::withMessages(['approval' => 'Configure a posting limit for this confirmer and currency.']);
        }
        if ($limit && Money::minor($amount) > Money::minor($limit->maximum_amount)) {
            throw ValidationException::withMessages(['approval' => 'Amount exceeds your posting limit. An authorized confirmer must post this transaction.']);
        }
        if ($limit && ! $limit->allow_self_approval && $creatorId === (int) $actor->id) {
            throw ValidationException::withMessages(['approval' => 'A different authorized user must confirm this transaction.']);
        }
    }

    public function configure(int $branchId, array $data, User $actor): FinancialBranchSetting
    {
        return DB::transaction(function () use ($branchId, $data, $actor) {
            BranchAccess::authorize($actor, $branchId);
            Branch::query()->lockForUpdate()->findOrFail($branchId);
            if (! in_array($data['default_currency'], $data['enabled_currencies'], true)) {
                throw ValidationException::withMessages(['default_currency' => 'Default currency must be enabled.']);
            }
            if (Cashbook::query()->where('branch_id', $branchId)->whereNotIn('currency_code', $data['enabled_currencies'])->exists()) {
                throw ValidationException::withMessages(['enabled_currencies' => 'Keep every existing cashbook currency enabled.']);
            }

            return FinancialBranchSetting::query()->updateOrCreate(['branch_id' => $branchId], $data);
        });
    }

    public function setLimit(int $branchId, array $data, User $actor): FinancialPostingLimit
    {
        return DB::transaction(function () use ($branchId, $data, $actor) {
            BranchAccess::authorize($actor, $branchId);
            Branch::query()->lockForUpdate()->findOrFail($branchId);
            $user = User::query()->findOrFail($data['user_id']);
            BranchAccess::authorize($user, $branchId);
            if ($user->account_status !== 'active') {
                throw ValidationException::withMessages(['user_id' => 'Choose an active user assigned to this branch.']);
            }
            $settings = FinancialBranchSetting::query()->where('branch_id', $branchId)->first();
            if (! $settings || ! in_array($data['currency_code'], $settings->enabled_currencies, true)) {
                throw ValidationException::withMessages(['currency_code' => 'Configure this branch and enable the currency first.']);
            }
            $data['maximum_amount'] = Money::decimal(Money::minor($data['maximum_amount']));

            return FinancialPostingLimit::query()->updateOrCreate(['branch_id' => $branchId, 'user_id' => $user->id, 'currency_code' => $data['currency_code']], $data);
        });
    }

    public function createPeriod(array $data, User $actor): FinancialPeriod
    {
        return DB::transaction(function () use ($data, $actor) {
            BranchAccess::authorize($actor, (int) $data['branch_id']);
            Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
            if (FinancialPeriod::query()->where('branch_id', $data['branch_id'])->whereDate('from_date', '<=', $data['to_date'])->whereDate('to_date', '>=', $data['from_date'])->exists()) {
                throw ValidationException::withMessages(['from_date' => 'Financial periods in a branch cannot overlap.']);
            }

            return FinancialPeriod::query()->create($data + ['state' => 'open']);
        });
    }

    public function transition(FinancialPeriod $period, bool $close, string $reason, User $actor): FinancialPeriod
    {
        return DB::transaction(function () use ($period, $close, $reason, $actor) {
            BranchAccess::authorize($actor, (int) $period->branch_id);
            Branch::query()->lockForUpdate()->findOrFail($period->branch_id);
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->state === ($close ? 'closed' : 'open')) {
                return $period;
            }
            if ($close) {
                if ($period->to_date->toDateString() > $this->businessDate((int) $period->branch_id)) {
                    throw ValidationException::withMessages(['period' => 'Future periods cannot be closed.']);
                }
                $range = [$period->from_date->toDateString(), $period->to_date->toDateString()];
                $draftCash = CashbookTransaction::query()->whereHas('cashbook', fn ($q) => $q->where('branch_id', $period->branch_id))->where('status', 'draft')->whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->exists();
                $draftPayments = PurchasePayment::query()->where('branch_id', $period->branch_id)->where('state', 'draft')->whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->exists();
                $payrollDrafts = StaffPayrollRecovery::query()->where('branch_id', $period->branch_id)->where('state', 'draft')->whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->exists();
                $transferDrafts = CashbookTransfer::query()->where('branch_id', $period->branch_id)->where('state', 'draft')->whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->exists();
                $due = DepreciationScheduleLine::query()->whereHas('depreciation', fn ($q) => $q->where('branch_id', $period->branch_id)->where('status', 'active'))->whereIn('status', ['pending', 'failed'])->whereDate('due_date', '>=', $range[0])->whereDate('due_date', '<=', $range[1])->exists();
                if ($draftCash || $draftPayments || $payrollDrafts || $transferDrafts || $due) {
                    throw ValidationException::withMessages(['period' => 'Resolve cash, supplier payment, payroll and transfer drafts and due depreciation lines before closing.']);
                }
                $checks = app(FinancialReconciliationService::class)->run(['branch_id' => $period->branch_id], $actor);
                if (! $checks['passed']) {
                    throw ValidationException::withMessages(['period' => 'Reconciliation failed. Correct the listed source records before closing.']);
                }
                $unclassified = CashbookLedgerEntry::query()->with('classification')
                    ->whereHas('cashbook', fn ($q) => $q->where('branch_id', $period->branch_id))
                    ->where('source_type', '<>', 'opening_balance')->whereDate('entry_date', '>=', $range[0])->whereDate('entry_date', '<=', $range[1])
                    ->get()->contains(fn ($entry) => $entry->effectiveTreatment() === 'unclassified');
                if ($unclassified) {
                    throw ValidationException::withMessages(['period' => 'Classify all cash movements before closing to preserve a complete cash result report.']);
                }
                $period->reconciliation_snapshot = $checks;
                $period->closed_by_id = $actor->id;
                $period->closed_at = now();
            }
            $period->state = $close ? 'closed' : 'open';
            $period->save();
            activity('financial')->causedBy($actor)->performedOn($period)->withProperties(['reason' => trim($reason), 'state' => $period->state])->log($close ? 'period_closed' : 'period_reopened');

            return $period->refresh();
        });
    }
}
