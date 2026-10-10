<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffAdvanceLedgerEntry;
use App\Modules\Financial\Models\StaffPayrollRecovery;
use App\Modules\Financial\Support\Money;
use App\Modules\Setup\Models\Staff;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollRecoveryService
{
    public function __construct(private readonly StaffAdvanceLedgerService $ledger, private readonly FinancialControlService $controls) {}

    public function list(StaffAdvance $advance, User $actor)
    {
        BranchAccess::authorize($actor, (int) $advance->branch_id);

        return StaffPayrollRecovery::query()->where('staff_advance_id', $advance->id)->orderBy('id')->get();
    }

    public function create(StaffAdvance $advance, array $data, User $actor): StaffPayrollRecovery
    {
        return DB::transaction(function () use ($advance, $data, $actor) {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            BranchAccess::authorize($actor, (int) $advance->branch_id);
            $existing = StaffPayrollRecovery::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $matches = (int) $existing->staff_advance_id === (int) $advance->id && Money::minor($existing->amount) === Money::minor($data['amount']) && $existing->business_date->toDateString() === $data['business_date'];
                foreach (['payroll_reference', 'evidence_reference', 'description'] as $field) {
                    $matches = $matches && $existing->{$field} === trim($data[$field]);
                }
                if (! $matches) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was used for different payroll recovery data.']);
                }

                return $existing;
            }
            $this->assertLoan($advance, Money::minor($data['amount']));
            if (StaffPayrollRecovery::query()->where('staff_advance_id', $advance->id)->where('payroll_reference', trim($data['payroll_reference']))->exists()) {
                throw ValidationException::withMessages(['payroll_reference' => 'This payroll reference has already been recorded for the loan.']);
            }

            return StaffPayrollRecovery::query()->create([
                'staff_advance_id' => $advance->id, 'branch_id' => $advance->branch_id, 'currency_code' => $advance->currency_code,
                'reference' => 'PAYR-'.Str::upper((string) Str::ulid()), 'idempotency_key' => $data['idempotency_key'],
                'payroll_reference' => trim($data['payroll_reference']), 'evidence_reference' => trim($data['evidence_reference']),
                'business_date' => $data['business_date'], 'amount' => Money::decimal(Money::minor($data['amount'])),
                'description' => trim($data['description']), 'created_by_id' => $actor->id, 'state' => 'draft',
            ]);
        });
    }

    public function confirm(StaffPayrollRecovery $recovery, string $note, User $actor): StaffPayrollRecovery
    {
        return DB::transaction(function () use ($recovery, $note, $actor) {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($recovery->staff_advance_id);
            $recovery = StaffPayrollRecovery::query()->lockForUpdate()->findOrFail($recovery->id);
            BranchAccess::authorize($actor, (int) $recovery->branch_id);
            if ($recovery->state === 'confirmed') {
                return $recovery;
            }
            if ($recovery->state !== 'draft') {
                throw ValidationException::withMessages(['recovery' => 'Only draft payroll recoveries can be confirmed.']);
            }
            $staff = Staff::withTrashed()->lockForUpdate()->findOrFail($advance->staff_id);
            $this->assertLoan($advance, Money::minor($recovery->amount));
            if ($recovery->business_date->lt($advance->business_date)) {
                throw ValidationException::withMessages(['business_date' => 'Recovery cannot precede loan issuance.']);
            }
            $this->controls->assertDate((int) $advance->branch_id, $recovery->business_date->toDateString(), $actor);
            $this->controls->assertApproval((int) $advance->branch_id, $advance->currency_code, $recovery->amount, (int) $recovery->created_by_id, $actor);
            $this->ledger->record($staff, $advance, null, null, null, $recovery->reference, $recovery->business_date->toDateString(),
                'payroll_recovery', 'deduction', $recovery->amount, $recovery->description, $actor, $recovery->id);
            $recovery->fill(['state' => 'confirmed', 'verification_note' => trim($note), 'verified_by_id' => $actor->id, 'verified_at' => now()])->save();

            return $recovery->refresh();
        });
    }

    public function reverse(StaffPayrollRecovery $recovery, string $reason, User $actor): StaffPayrollRecovery
    {
        return DB::transaction(function () use ($recovery, $reason, $actor) {
            $advance = StaffAdvance::query()->lockForUpdate()->findOrFail($recovery->staff_advance_id);
            $recovery = StaffPayrollRecovery::query()->lockForUpdate()->findOrFail($recovery->id);
            BranchAccess::authorize($actor, (int) $advance->branch_id);
            if ($recovery->state === 'reversed') {
                return $recovery;
            }
            if ($recovery->state !== 'confirmed' || $advance->status !== 'confirmed') {
                throw ValidationException::withMessages(['recovery' => 'Only confirmed recovery against an open loan can be reversed.']);
            }
            $staff = Staff::withTrashed()->lockForUpdate()->findOrFail($advance->staff_id);
            $date = $this->controls->businessDate((int) $advance->branch_id);
            $this->controls->assertDate((int) $advance->branch_id, $date, $actor);
            $this->controls->assertApproval((int) $advance->branch_id, $advance->currency_code, $recovery->amount, (int) $recovery->created_by_id, $actor);
            $original = StaffAdvanceLedgerEntry::query()->where('payroll_recovery_id', $recovery->id)->where('entry_type', 'payroll_recovery')->firstOrFail();
            $this->ledger->record($staff, $advance, null, null, $original, 'PRV-'.Str::upper((string) Str::ulid()), $date,
                'payroll_recovery_reversal', 'addition', $recovery->amount, 'Reversal of '.$recovery->reference.': '.trim($reason), $actor, $recovery->id);
            $recovery->fill(['state' => 'reversed', 'reversal_reason' => trim($reason)])->save();

            return $recovery->refresh();
        });
    }

    public function cancel(StaffPayrollRecovery $recovery, User $actor): StaffPayrollRecovery
    {
        return DB::transaction(function () use ($recovery, $actor) {
            $recovery = StaffPayrollRecovery::query()->lockForUpdate()->findOrFail($recovery->id);
            BranchAccess::authorize($actor, (int) $recovery->branch_id);
            if ($recovery->state !== 'draft') {
                throw ValidationException::withMessages(['recovery' => 'Only draft recoveries can be cancelled.']);
            }
            $recovery->state = 'cancelled';
            $recovery->save();

            return $recovery;
        });
    }

    private function assertLoan(StaffAdvance $advance, int $amount): void
    {
        if ($advance->status !== 'confirmed' || $amount <= 0 || $amount > $this->ledger->loanBalanceMinor($advance->id)) {
            throw ValidationException::withMessages(['amount' => 'Use a positive amount no greater than the confirmed loan outstanding balance.']);
        }
    }
}
