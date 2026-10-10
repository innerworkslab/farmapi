<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Models\CashEntryClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashEntryClassificationService
{
    public function classify(CashbookLedgerEntry $entry, array $data, User $actor): CashEntryClassification
    {
        return DB::transaction(function () use ($entry, $data, $actor) {
            $controls = app(FinancialControlService::class);
            $book = $controls->lockCashbook((int) $entry->cashbook_id, $actor);
            $entry = CashbookLedgerEntry::query()->with('classification')->findOrFail($entry->id);
            if ($entry->classification) {
                if ($entry->classification->report_treatment === $data['report_treatment']) {
                    return $entry->classification;
                }
                throw ValidationException::withMessages(['report_treatment' => 'This entry was already classified. Use a source reversal for a correction.']);
            }
            if ($entry->report_treatment !== 'unclassified' || $entry->source_type === 'opening_balance' || $entry->reversal_of_entry_id) {
                throw ValidationException::withMessages(['entry' => 'Only unclassified original movements can be classified. Paired reversals inherit their original treatment.']);
            }
            $treatment = $data['report_treatment'];
            if (in_array($entry->source_type, ['staff_advance_disbursement', 'staff_advance_repayment', 'cashbook_transfer'], true) && $treatment !== 'excluded') {
                throw ValidationException::withMessages(['report_treatment' => 'Staff loan movements and internal transfers must be excluded from the cash result.']);
            }
            if (($treatment === 'income' && $entry->direction !== 'in') || ($treatment === 'expense' && $entry->direction !== 'out')) {
                throw ValidationException::withMessages(['report_treatment' => 'Income requires cash in and expense requires cash out.']);
            }
            $entries = collect([$entry])->merge(CashbookLedgerEntry::query()->where('reversal_of_entry_id', $entry->id)->get());
            foreach ($entries as $related) {
                $controls->assertDate((int) $book->branch_id, $related->entry_date->toDateString(), $actor);
                CashEntryClassification::query()->create(['cashbook_ledger_entry_id' => $related->id, 'report_treatment' => $treatment,
                    'reason' => trim($data['reason']), 'created_by_id' => $actor->id]);
            }

            return CashEntryClassification::query()->where('cashbook_ledger_entry_id', $entry->id)->firstOrFail();
        });
    }
}
