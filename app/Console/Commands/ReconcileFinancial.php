<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Financial\Services\FinancialReconciliationService;
use Illuminate\Console\Command;

class ReconcileFinancial extends Command
{
    protected $signature = 'financial:reconcile {--user= : Authorized active finance user ID} {--branch= : Optional authorized branch ID} {--json : Output the complete reconciliation as JSON}';

    protected $description = 'Check financial ledgers and source records without changing any balance';

    public function handle(FinancialReconciliationService $reconciliation): int
    {
        $actor = User::query()->find($this->option('user'));
        if (! $actor || $actor->account_status !== 'active' || ! $actor->can('financial.reconciliation.view')) {
            $this->error('Provide --user with an active user authorized to view financial reconciliation.');

            return self::FAILURE;
        }
        $result = $reconciliation->run(array_filter(['branch_id' => $this->option('branch')]), $actor);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info(count($result['checks']).' checks; '.($result['passed'] ? 'passed' : 'mismatches found'));
            $failed = collect($result['checks'])->where('passed', false)->map(fn ($check) => [
                $check['check'], $check['record_type'], $check['record_id'], $check['currency_code'], $check['expected'], $check['actual'], $check['delta'],
            ])->all();
            if ($failed) {
                $this->table(['Check', 'Record', 'ID', 'Currency', 'Expected', 'Actual', 'Delta'], $failed);
            }
        }

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
