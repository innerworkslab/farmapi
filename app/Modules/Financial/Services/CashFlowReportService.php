<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Support\Money;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashFlowReportService
{
    public function report(array $filters, User $actor, bool $detail = false): array
    {
        return DB::transaction(fn () => $this->buildReport($filters, $actor, $detail));
    }

    private function buildReport(array $filters, User $actor, bool $detail): array
    {
        $from = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to_date'] ?? now()->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['from_date' => 'Start date must precede the end date.']);
        }
        if (! empty($filters['branch_id'])) {
            BranchAccess::authorize($actor, (int) $filters['branch_id']);
        }
        if (! empty($filters['cashbook_id'])) {
            app(CashbookService::class)->find(Cashbook::query()->findOrFail($filters['cashbook_id']), $actor);
        }
        $books = BranchAccess::scope(Cashbook::query(), $actor)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['cashbook_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['currency_code'] ?? null, fn ($q, $code) => $q->where('currency_code', $code))->get()->keyBy('id');
        $entries = CashbookLedgerEntry::query()->with(['category', 'classification'])->whereIn('cashbook_id', $books->modelKeys())
            ->where('source_type', '<>', 'opening_balance')->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->orderBy('entry_date')->orderBy('id')->get();
        $groups = [];
        foreach ($entries as $entry) {
            $currency = $books[$entry->cashbook_id]->currency_code;
            $groups[$currency] ??= ['currency_code' => $currency, 'income' => 0, 'expense' => 0, 'excluded_in' => 0, 'excluded_out' => 0, 'unclassified_in' => 0, 'unclassified_out' => 0, 'unclassified_count' => 0, 'lines' => [], 'entries' => []];
            $group = &$groups[$currency];
            $treatment = $entry->effectiveTreatment();
            $amount = Money::minor($entry->amount);
            $effect = $entry->reversal_of_entry_id ? -$amount : $amount;
            if (in_array($treatment, ['income', 'expense'], true)) {
                $group[$treatment] += $effect;
            } elseif ($treatment === 'excluded') {
                $group['excluded_'.$entry->direction] += $amount;
            } else {
                $group['unclassified_'.$entry->direction] += $amount;
                $group['unclassified_count']++;
            }
            $key = $entry->category_id.'-'.$treatment;
            $group['lines'][$key] ??= ['category_id' => $entry->category_id, 'category_name' => $entry->category?->name, 'report_treatment' => $treatment, 'amount' => 0, 'entry_count' => 0];
            $group['lines'][$key]['amount'] += in_array($treatment, ['income', 'expense'], true) ? $effect : ($entry->direction === 'in' ? $amount : -$amount);
            $group['lines'][$key]['entry_count']++;
            if ($detail) {
                $group['entries'][] = ['id' => $entry->id, 'cashbook_id' => $entry->cashbook_id, 'business_date' => $entry->entry_date->toDateString(),
                    'reference' => $entry->reference, 'source_type' => $entry->source_type, 'description' => $entry->description,
                    'category_id' => $entry->category_id, 'report_treatment' => $treatment, 'direction' => $entry->direction,
                    'amount' => Money::decimal($amount), 'report_effect' => in_array($treatment, ['income', 'expense'], true) ? Money::decimal($effect) : null,
                    'reversal_of_entry_id' => $entry->reversal_of_entry_id];
            }
            unset($group);
        }
        $reconciliation = app(FinancialReconciliationService::class)->run($filters, $actor);
        foreach ($groups as &$group) {
            $group['is_complete'] = $group['unclassified_count'] === 0 && $reconciliation['passed'];
            $group['net_cash_result'] = $group['is_complete'] ? Money::decimal($group['income'] - $group['expense']) : null;
            foreach (['income', 'expense', 'excluded_in', 'excluded_out', 'unclassified_in', 'unclassified_out'] as $key) {
                $group[$key] = Money::decimal($group[$key]);
            }
            $group['lines'] = array_values(array_map(function ($line) {
                $line['amount'] = Money::decimal($line['amount']);

                return $line;
            }, $group['lines']));
            if (! $detail) {
                unset($group['entries']);
            }
        }
        unset($group);

        return ['basis' => 'single_entry_cash', 'from_date' => $from, 'to_date' => $to, 'filters' => $filters,
            'generated_at' => now()->toISOString(), 'reconciliation_passed' => $reconciliation['passed'],
            'reconciliation_issue_count' => collect($reconciliation['checks'])->where('passed', false)->count(),
            'reconciliation_issues' => $actor->can('financial.reconciliation.view') ? collect($reconciliation['checks'])->where('passed', false)->values()->all() : [],
            'is_complete' => $reconciliation['passed'] && ! collect($groups)->contains('is_complete', false),
            'currencies' => array_values($groups)];
    }
}
