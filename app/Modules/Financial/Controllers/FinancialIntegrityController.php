<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Requests\CashbookSummaryRequest;
use App\Modules\Financial\Requests\CashEntryClassificationRequest;
use App\Modules\Financial\Services\CashEntryClassificationService;
use App\Modules\Financial\Services\CashFlowReportService;
use App\Modules\Financial\Services\FinancialReconciliationService;

class FinancialIntegrityController extends Controller
{
    public function classify(CashEntryClassificationRequest $request, CashbookLedgerEntry $entry, CashEntryClassificationService $classifications)
    {
        return response()->json(['data' => $classifications->classify($entry, $request->validated(), $request->user())]);
    }

    public function reconcile(CashbookSummaryRequest $request, FinancialReconciliationService $checks)
    {
        return response()->json(['data' => $checks->run($request->validated(), $request->user())]);
    }

    public function cashResult(CashbookSummaryRequest $request, CashFlowReportService $reports)
    {
        return response()->json(['data' => $reports->report($request->validated(), $request->user(), $request->user()->can('financial.reports.detail'))]);
    }

    public function export(CashbookSummaryRequest $request, CashFlowReportService $reports)
    {
        $report = $reports->report($request->validated(), $request->user(), true);
        activity('financial')->causedBy($request->user())->withProperties(['filters' => $report['filters'], 'generated_at' => $report['generated_at']])->log('cash_result_exported');

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $safe = fn ($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'".$value : $value;
            fputcsv($out, ['Single-entry cash result', $report['from_date'], $report['to_date'], $report['generated_at']], ',', '"', '');
            fputcsv($out, ['Filters', json_encode($report['filters']), 'Complete', $report['is_complete'] ? 'yes' : 'no'], ',', '"', '');
            fputcsv($out, ['Currency', 'Income', 'Expense', 'Net cash result', 'Unclassified entries'], ',', '"', '');
            foreach ($report['currencies'] as $group) {
                fputcsv($out, [$group['currency_code'], $group['income'], $group['expense'], $group['net_cash_result'], $group['unclassified_count']], ',', '"', '');
            }
            fputcsv($out, ['Currency', 'Entry ID', 'Cashbook', 'Date', 'Reference', 'Source', 'Description', 'Category', 'Treatment', 'Direction', 'Amount', 'Report effect'], ',', '"', '');
            foreach ($report['currencies'] as $group) {
                foreach ($group['entries'] as $entry) {
                    fputcsv($out, [$group['currency_code'], $entry['id'], $entry['cashbook_id'], $entry['business_date'], $safe($entry['reference']), $safe($entry['source_type']), $safe($entry['description']), $entry['category_id'], $entry['report_treatment'], $entry['direction'], $entry['amount'], $entry['report_effect']], ',', '"', '');
                }
            }
            fclose($out);
        }, 'financial-cash-result.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
