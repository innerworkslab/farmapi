<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffAdvanceLedgerEntry;
use App\Modules\Financial\Models\StaffAdvanceRepayment;
use App\Modules\Setup\Models\Staff;
use Illuminate\Validation\ValidationException;

class StaffAdvanceLedgerService
{
    public function staffBalanceMinor(int $staffId): int
    {
        $balance = StaffAdvanceLedgerEntry::query()->where('staff_id', $staffId)->orderByDesc('id')->value('running_balance');

        return $balance === null ? 0 : $this->toMinor((string) $balance);
    }

    public function loanBalanceMinor(int $advanceId): int
    {
        $balance = StaffAdvanceLedgerEntry::query()->where('staff_advance_id', $advanceId)
            ->selectRaw("COALESCE(SUM(CASE WHEN effect = 'addition' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');

        return $balance === null ? 0 : $this->toMinor((string) $balance);
    }

    public function record(
        Staff $staff,
        StaffAdvance $advance,
        ?StaffAdvanceRepayment $repayment,
        CashbookTransaction $cashbookTransaction,
        ?StaffAdvanceLedgerEntry $reversalOf,
        string $reference,
        string $businessDate,
        string $entryType,
        string $effect,
        string $amount,
        string $description,
        User $actor
    ): StaffAdvanceLedgerEntry {
        $amountMinor = $this->toMinor($amount);
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $previous = $this->staffBalanceMinor((int) $staff->id);
        $balance = $effect === 'addition' ? $previous + $amountMinor : $previous - $amountMinor;
        if ($balance < 0) {
            throw ValidationException::withMessages(['amount' => 'This transaction would create a negative staff advance balance.']);
        }

        return StaffAdvanceLedgerEntry::query()->create([
            'staff_id' => $staff->id,
            'branch_id' => $advance->branch_id,
            'staff_advance_id' => $advance->id,
            'staff_advance_repayment_id' => $repayment?->id,
            'cashbook_transaction_id' => $cashbookTransaction->id,
            'reversal_of_entry_id' => $reversalOf?->id,
            'reference' => $reference,
            'business_date' => $businessDate,
            'entry_type' => $entryType,
            'effect' => $effect,
            'amount' => $this->formatMinor($amountMinor),
            'running_balance' => $this->formatMinor($balance),
            'currency_code' => $advance->currency_code,
            'description' => $description,
            'created_by_id' => $actor->id,
        ]);
    }

    public function toMinor(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a non-negative value with at most two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public function formatMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
