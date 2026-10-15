<?php

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Modules\Financial\Models\AssetCategory;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\CashLedgerCategory;
use App\Modules\Financial\Models\DepreciationScheduleLine;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Financial\Models\FinancialPeriod;
use App\Modules\Financial\Models\FinancialPostingLimit;
use App\Modules\Financial\Services\CashbookService;
use App\Modules\Financial\Services\DepreciationService;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Financial\Services\StaffAdvanceLedgerService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use App\Modules\Setup\Models\Supplier;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoFinancialControlPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        $permissions = array_merge(DemoFinancialControlPermissionsSeeder::PERMISSIONS, [
            'financial.transactions.view', 'financial.transactions.create', 'financial.transactions.confirm', 'financial.transactions.reverse',
            'financial.reports.view', 'financial.advances.view', 'financial.advances.create', 'financial.advances.confirm', 'financial.advances.reverse',
            'financial.advance-repayments.create', 'financial.advance-repayments.confirm', 'financial.advance-repayments.reverse',
            'financial.categories.update', 'financial.cashbooks.create', 'financial.depreciations.create', 'financial.depreciations.activate',
            'financial.depreciations.post', 'financial.depreciations.reverse', 'purchasing.invoices.update', 'purchasing.invoices.cancel',
        ]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);
        $branch = Branch::query()->create(['code' => 'FIN', 'name' => 'Finance branch', 'status' => 'active']);
        $user->branches()->attach($branch->id);
        $out = CashLedgerCategory::query()->create(['name' => 'Purchases', 'normalized_name' => 'purchases', 'direction' => 'out', 'report_treatment' => 'expense', 'status' => 'active']);
        $in = CashLedgerCategory::query()->create(['name' => 'Receipts', 'normalized_name' => 'receipts', 'direction' => 'in', 'report_treatment' => 'income', 'reversal_category_id' => $out->id, 'status' => 'active']);
        $out->update(['reversal_category_id' => $in->id]);
        $period = app(FinancialControlService::class)->createPeriod(['branch_id' => $branch->id, 'name' => '2026', 'from_date' => '2026-01-01', 'to_date' => '2026-10-09'], $user);
        app(FinancialControlService::class)->configure($branch->id, ['default_currency' => 'MMK', 'enabled_currencies' => ['MMK', 'USD'], 'timezone' => 'Asia/Yangon', 'require_open_period' => true, 'require_approval_limit' => true], $user);
        $limit = app(FinancialControlService::class)->setLimit($branch->id, ['user_id' => $user->id, 'currency_code' => 'MMK', 'maximum_amount' => '1000.00', 'allow_self_approval' => true], $user);
        $book = app(CashbookService::class)->create(['branch_id' => $branch->id, 'name' => 'Finance cash', 'type' => 'cash', 'currency_code' => 'MMK', 'opening_balance' => '1000.00', 'effective_date' => '2026-01-01'], $user);
        $this->s = compact('user', 'branch', 'book', 'in', 'out', 'period', 'limit');
    }

    private function draft(string $amount = '10.00', string $direction = 'in', array $extra = []): int
    {
        return $this->postJson('/api/v1/financial/cashbook-transactions', array_merge([
            'cashbook_id' => $this->s['book']->id, 'category_id' => $this->s[$direction]->id, 'direction' => $direction,
            'amount' => $amount, 'business_date' => '2026-10-09', 'description' => 'Finance test',
            'idempotency_key' => (string) Str::ulid(),
        ], $extra))->assertCreated()->json('data.id');
    }

    private function confirm(int $id)
    {
        return $this->postJson('/api/v1/financial/cashbook-transactions/'.$id.'/confirm');
    }

    public function test_exact_cash_posting_reversal_and_classification_snapshots_reconcile(): void
    {
        $id = $this->draft('0.10');
        $this->confirm($id)->assertOk();
        $this->confirm($id)->assertOk();
        $this->assertDatabaseCount('cashbook_ledger_entries', 2);
        $this->postJson('/api/v1/financial/cash-ledger-categories/'.$this->s['in']->id, ['report_treatment' => 'excluded'])->assertOk();
        $this->getJson('/api/v1/financial/reports/cash-result?from_date=2026-10-09&to_date=2026-10-09')->assertOk()
            ->assertJsonPath('data.currencies.0.income', '0.10')->assertJsonPath('data.currencies.0.net_cash_result', '0.10');
        $this->postJson('/api/v1/financial/cashbook-transactions/'.$id.'/reverse', ['reason' => 'Correct receipt'])->assertCreated();
        $this->getJson('/api/v1/financial/reports/cash-result')->assertOk()->assertJsonPath('data.currencies.0.income', '0.00');
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
        $this->assertSame('1000.00', $this->s['book']->entries()->orderByDesc('id')->value('running_balance'));
    }

    public function test_period_close_blocks_manual_module_opening_and_reversal_posting_until_audited_reopen(): void
    {
        $id = $this->draft();
        $url = '/api/v1/financial/periods/'.$this->s['period']->id;
        $this->postJson($url.'/close', ['reason' => 'Month end'])->assertUnprocessable();
        $this->confirm($id)->assertOk();
        $this->postJson($url.'/close', ['reason' => 'Month end'])->assertOk()->assertJsonPath('data.state', 'closed');
        $blocked = $this->draft('5.00');
        $this->confirm($blocked)->assertUnprocessable()->assertJsonValidationErrors('business_date');
        $this->postJson('/api/v1/financial/cashbook-transactions/'.$id.'/reverse', ['reason' => 'Correct receipt'])->assertUnprocessable();
        $this->postJson('/api/v1/financial/cashbooks', ['branch_id' => $this->s['branch']->id, 'name' => 'Closed opening', 'type' => 'cash', 'opening_balance' => '0', 'effective_date' => '2026-10-09'])->assertUnprocessable();
        $this->assertDatabaseCount('cashbook_ledger_entries', 2);
        $this->postJson($url.'/reopen', [])->assertUnprocessable();
        $this->postJson($url.'/reopen', ['reason' => 'Authorized correction'])->assertOk();
        $this->confirm($blocked)->assertOk();
        $this->assertDatabaseHas('activity_log', ['description' => 'period_reopened']);
    }

    public function test_approval_limits_separate_creator_and_confirmer_and_fail_closed_when_missing(): void
    {
        $this->s['limit']->update(['allow_self_approval' => false, 'maximum_amount' => '20.00']);
        $id = $this->draft('10.00');
        $this->confirm($id)->assertUnprocessable()->assertJsonValidationErrors('approval');
        $other = User::factory()->create(['account_status' => 'active']);
        $other->givePermissionTo('financial.transactions.confirm');
        $other->branches()->attach($this->s['branch']->id);
        Sanctum::actingAs($other);
        $this->confirm($id)->assertUnprocessable()->assertJsonValidationErrors('approval');
        app(FinancialControlService::class)->setLimit($this->s['branch']->id, ['user_id' => $other->id, 'currency_code' => 'MMK', 'maximum_amount' => '5.00', 'allow_self_approval' => false], $this->s['user']);
        $this->confirm($id)->assertUnprocessable();
        FinancialPostingLimit::query()->where('user_id', $other->id)->update(['maximum_amount' => '20.00']);
        $this->confirm($id)->assertOk();
        $this->assertDatabaseHas('cashbook_transactions', ['id' => $id, 'created_by_id' => $this->s['user']->id, 'confirmed_by_id' => $other->id]);
    }

    public function test_reconciliation_identifies_corruption_and_blocks_posting_and_close_without_overwriting(): void
    {
        $id = $this->draft();
        $this->confirm($id)->assertOk();
        DB::table('cashbook_ledger_entries')->where('cashbook_transaction_id', $id)->update(['running_balance' => '999.00']);
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', false);
        $next = $this->draft('1.00');
        $this->confirm($next)->assertUnprocessable()->assertJsonValidationErrors('reconciliation');
        $this->assertDatabaseHas('cashbook_ledger_entries', ['cashbook_transaction_id' => $id, 'running_balance' => '999.00']);
        $this->assertDatabaseMissing('cashbook_ledger_entries', ['cashbook_transaction_id' => $next]);
    }

    public function test_currency_reports_are_separate_and_unclassified_cash_never_produces_a_final_net(): void
    {
        $book = app(CashbookService::class)->create(['branch_id' => $this->s['branch']->id, 'name' => 'USD', 'type' => 'bank', 'currency_code' => 'USD', 'opening_balance' => '0.00', 'effective_date' => '2026-10-09'], $this->s['user']);
        app(FinancialControlService::class)->setLimit($this->s['branch']->id, ['user_id' => $this->s['user']->id, 'currency_code' => 'USD', 'maximum_amount' => '100', 'allow_self_approval' => true], $this->s['user']);
        $this->s['in']->update(['report_treatment' => 'unclassified']);
        $this->confirm($this->draft('12.34', 'in', ['cashbook_id' => $book->id]))->assertOk();
        $this->s['in']->update(['report_treatment' => 'income']);
        $this->confirm($this->draft('1.23'))->assertOk();
        $report = $this->getJson('/api/v1/financial/reports/cash-result')->assertOk()->assertJsonPath('data.is_complete', false)->json('data.currencies');
        $groups = collect($report)->keyBy('currency_code');
        $this->assertSame('1.23', $groups['MMK']['income']);
        $this->assertNull($groups['USD']['net_cash_result']);
        $this->assertSame('12.34', $groups['USD']['unclassified_in']);
    }

    public function test_scope_and_action_permissions_protect_controls_and_detail_exports(): void
    {
        $foreign = Branch::query()->create(['code' => 'FOREIGN', 'name' => 'Other branch', 'status' => 'active']);
        $this->getJson('/api/v1/financial/branch-settings/'.$foreign->id)->assertForbidden();
        $this->assertDatabaseHas('activity_log', ['description' => 'financial_access_denied']);
        $this->getJson('/api/v1/financial/reconciliation?branch_id='.$foreign->id)->assertForbidden();
        $this->s['user']->revokePermissionTo('financial.reports.detail');
        $this->getJson('/api/v1/financial/reports/cash-result/export')->assertForbidden();
        $this->s['user']->revokePermissionTo('financial.periods.reopen');
        $this->postJson('/api/v1/financial/periods/'.$this->s['period']->id.'/reopen', ['reason' => 'Unauthorized correction'])->assertForbidden();
    }

    public function test_purchase_payments_post_once_reject_overpayment_and_reverse_through_the_owner(): void
    {
        $supplier = Supplier::query()->create(['code' => 'SUP-FIN', 'type' => 'business', 'name' => 'Supplier', 'phone_number' => '0912345', 'status' => 'active']);
        $invoice = PurchaseInvoice::query()->create(['invoice_number' => 'PINV-FIN', 'invoice_date' => '2026-10-09', 'supplier_id' => $supplier->id, 'branch_id' => $this->s['branch']->id, 'status' => 'open', 'delivery_status' => 'not_received', 'total_amount' => '100.00', 'created_by_id' => $this->s['user']->id]);
        $url = '/api/v1/purchasing/invoices/'.$invoice->id.'/payments';
        $this->getJson($url)->assertOk()->assertJsonPath('data.currency_code', 'MMK')->assertJsonPath('data.eligible_cashbooks.0.id', $this->s['book']->id);
        $data = ['cashbook_id' => $this->s['book']->id, 'category_id' => $this->s['out']->id, 'invoice_currency_code' => 'MMK', 'amount' => '60.00', 'description' => 'Supplier payment', 'idempotency_key' => 'purchase-1'];
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/purchasing/payments/'.$id.'/confirm')->assertOk();
        $this->postJson('/api/v1/purchasing/payments/'.$id.'/confirm')->assertOk();
        $this->assertDatabaseCount('cashbook_transactions', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.outstanding_amount', '40.00');
        $this->postJson($url, array_merge($data, ['idempotency_key' => 'purchase-2', 'amount' => '41.00']))->assertUnprocessable();
        $this->postJson('/api/v1/purchasing/invoices/'.$invoice->id.'/cancel', ['reason' => 'Invalid invoice'])->assertUnprocessable();
        $txn = CashbookTransaction::query()->first();
        $this->postJson('/api/v1/financial/cashbook-transactions/'.$txn->id.'/reverse', ['reason' => 'Bypass owner'])->assertUnprocessable();
        $this->postJson('/api/v1/purchasing/payments/'.$id.'/reverse', ['reason' => 'Payment correction'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.outstanding_amount', '100.00');
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_payroll_recovery_reduces_loan_without_cash_and_requires_evidence_verification(): void
    {
        $staff = Staff::query()->create(['staff_code' => 'ST-FIN', 'normalized_staff_code' => 'st-fin', 'name' => 'Staff', 'branch_id' => $this->s['branch']->id, 'employment_status' => 'employed', 'status' => 'active']);
        $advanceId = $this->postJson('/api/v1/financial/staff-advances', ['staff_id' => $staff->id, 'principal_amount' => '100', 'cashbook_id' => $this->s['book']->id, 'category_id' => $this->s['out']->id, 'business_date' => '2026-10-09', 'description' => 'Staff loan', 'idempotency_key' => 'loan-1'])->assertOk()->json('data.id');
        $this->postJson('/api/v1/financial/staff-advances/'.$advanceId.'/confirm')->assertOk();
        $url = '/api/v1/financial/staff-advances/'.$advanceId.'/payroll-recoveries';
        $payload = ['amount' => '30.00', 'business_date' => '2026-10-09', 'payroll_reference' => 'PAYROLL-OCT-STAFF-1', 'evidence_reference' => 'approved-payroll/2026-10/staff-1', 'description' => 'Salary deduction', 'idempotency_key' => 'payroll-1'];
        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/financial/payroll-recoveries/'.$id.'/confirm', [])->assertUnprocessable();
        $this->postJson('/api/v1/financial/payroll-recoveries/'.$id.'/confirm', ['verification_note' => 'Matched approved payslip deduction'])->assertOk();
        $this->postJson('/api/v1/financial/payroll-recoveries/'.$id.'/confirm', ['verification_note' => 'Matched approved payslip deduction'])->assertOk();
        $this->assertSame(7000, app(StaffAdvanceLedgerService::class)->loanBalanceMinor($advanceId));
        $this->assertDatabaseCount('cashbook_transactions', 1);
        $this->assertDatabaseHas('staff_advance_ledger_entries', ['payroll_recovery_id' => $id, 'cashbook_transaction_id' => null, 'entry_type' => 'payroll_recovery']);
        $this->postJson($url, array_merge($payload, ['amount' => '71.00', 'idempotency_key' => 'payroll-2', 'payroll_reference' => 'PAYROLL-NOV-STAFF-1']))->assertUnprocessable();
        $this->postJson('/api/v1/financial/staff-advances/'.$advanceId.'/reverse', ['reason' => 'Reverse loan'])->assertUnprocessable();
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
        $this->getJson('/api/v1/financial/reports/cash-result')->assertOk()->assertJsonPath('data.currencies.0.expense', '0.00')->assertJsonPath('data.currencies.0.excluded_out', '100.00');
        $this->postJson('/api/v1/financial/payroll-recoveries/'.$id.'/reverse', ['reason' => 'Payroll correction'])->assertOk();
        $this->assertSame(10000, app(StaffAdvanceLedgerService::class)->loanBalanceMinor($advanceId));
        $this->assertDatabaseCount('cashbook_transactions', 1);
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_backdate_permission_period_overlap_and_missing_period_are_rechecked(): void
    {
        $this->s['user']->revokePermissionTo('financial.transactions.backdate');
        $id = $this->draft('1.00', 'in', ['business_date' => '2026-10-08']);
        $this->confirm($id)->assertUnprocessable()->assertJsonValidationErrors('business_date');
        $this->postJson('/api/v1/financial/periods', ['branch_id' => $this->s['branch']->id, 'name' => 'Overlap', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31'])->assertUnprocessable();
        FinancialPeriod::query()->whereKey($this->s['period']->id)->update(['to_date' => '2026-10-08']);
        $this->confirm($this->draft('1.00'))->assertUnprocessable();
    }

    public function test_cashbook_transfer_posts_both_sides_atomically_and_is_excluded_from_income_and_expense(): void
    {
        $destination = app(CashbookService::class)->create(['branch_id' => $this->s['branch']->id, 'name' => 'Bank MMK', 'type' => 'bank', 'opening_balance' => '0.00', 'effective_date' => '2026-10-09'], $this->s['user']);
        $payload = ['source_cashbook_id' => $this->s['book']->id, 'destination_cashbook_id' => $destination->id, 'out_category_id' => $this->s['out']->id, 'in_category_id' => $this->s['in']->id, 'amount' => '100', 'description' => 'Cash deposit', 'idempotency_key' => 'transfer-1'];
        $id = $this->postJson('/api/v1/financial/cashbook-transfers', $payload)->assertCreated()->json('data.id');
        $this->s['in']->update(['status' => 'inactive']);
        $this->postJson('/api/v1/financial/cashbook-transfers/'.$id.'/confirm')->assertUnprocessable();
        $this->assertDatabaseCount('cashbook_transactions', 0);
        $this->s['in']->update(['status' => 'active']);
        $this->postJson('/api/v1/financial/cashbook-transfers/'.$id.'/confirm')->assertOk();
        $this->postJson('/api/v1/financial/cashbook-transfers/'.$id.'/confirm')->assertOk();
        $this->assertDatabaseCount('cashbook_transactions', 2);
        $this->assertSame('900.00', $this->s['book']->entries()->orderByDesc('id')->value('running_balance'));
        $this->assertSame('100.00', $destination->entries()->orderByDesc('id')->value('running_balance'));
        $this->getJson('/api/v1/financial/reports/cash-result')->assertOk()->assertJsonPath('data.currencies.0.income', '0.00')->assertJsonPath('data.currencies.0.expense', '0.00');
        $this->postJson('/api/v1/financial/cashbook-transfers/'.$id.'/reverse', ['reason' => 'Bank deposit correction'])->assertOk();
        $this->assertSame('1000.00', $this->s['book']->entries()->orderByDesc('id')->value('running_balance'));
        $this->assertSame('0.00', $destination->entries()->orderByDesc('id')->value('running_balance'));
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_depreciation_exact_final_deduction_reconciles_and_closed_period_failure_is_visible(): void
    {
        $category = AssetCategory::query()->create(['name' => 'Machines', 'normalized_name' => 'machines', 'status' => 'active']);
        $id = $this->postJson('/api/v1/financial/depreciations', ['asset_name' => 'Machine', 'asset_category_id' => $category->id, 'asset_price' => '10.00', 'monthly_amount' => '6.00', 'start_date' => '2026-09-09', 'cashbook_id' => $this->s['book']->id, 'category_id' => $this->s['out']->id, 'idempotency_key' => 'dep-1'])->assertOk()->json('data.id');
        $this->postJson('/api/v1/financial/depreciations/'.$id.'/activate')->assertOk();
        $lines = DepreciationScheduleLine::query()->where('depreciation_id', $id)->orderBy('id')->get();
        $this->assertSame('6.00', $lines[0]->amount);
        $this->assertSame('4.00', $lines[1]->amount);
        $this->postJson('/api/v1/financial/depreciation-schedule-lines/'.$lines[0]->id.'/post')->assertOk();
        $this->postJson('/api/v1/financial/depreciation-schedule-lines/'.$lines[1]->id.'/post')->assertOk();
        $this->assertDatabaseHas('depreciations', ['id' => $id, 'total_posted_amount' => '10.00', 'status' => 'completed']);
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
        $this->postJson('/api/v1/financial/depreciation-schedule-lines/'.$lines[1]->id.'/reverse', ['reason' => 'Correction'])->assertOk();
        $this->assertDatabaseHas('depreciations', ['id' => $id, 'total_posted_amount' => '6.00', 'status' => 'active']);
        $this->getJson('/api/v1/financial/reconciliation')->assertOk()->assertJsonPath('data.passed', true);
        $pending = $this->postJson('/api/v1/financial/depreciations', ['asset_name' => 'Second machine', 'asset_category_id' => $category->id, 'asset_price' => '2.00', 'monthly_amount' => '2.00', 'start_date' => '2026-10-09', 'cashbook_id' => $this->s['book']->id, 'category_id' => $this->s['out']->id, 'idempotency_key' => 'dep-2'])->assertOk()->json('data.id');
        $this->postJson('/api/v1/financial/depreciations/'.$pending.'/activate')->assertOk();
        $this->postJson('/api/v1/financial/periods/'.$this->s['period']->id.'/close', ['reason' => 'Period close'])->assertUnprocessable();
        FinancialPeriod::query()->whereKey($this->s['period']->id)->update(['state' => 'closed']);
        $result = app(DepreciationService::class)->postDueLines(CarbonImmutable::parse('2026-10-09'), $this->s['user']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('depreciation_schedule_lines', ['depreciation_id' => $pending, 'status' => 'failed']);
    }

    public function test_report_export_preserves_filters_and_escapes_spreadsheet_formulas(): void
    {
        $this->confirm($this->draft('3.21', 'in', ['description' => '=SUM(1,2)']))->assertOk();
        $response = $this->get('/api/v1/financial/reports/cash-result/export?from_date=2026-10-09&to_date=2026-10-09&currency_code=MMK')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('2026-10-09', $csv);
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $this->assertStringContainsString('3.21', $csv);
        $this->assertDatabaseHas('activity_log', ['description' => 'cash_result_exported']);
    }

    public function test_historical_classification_is_audited_without_editing_ledger_and_requires_open_period(): void
    {
        $this->s['in']->update(['report_treatment' => 'unclassified']);
        $id = $this->draft('5.00');
        $this->confirm($id)->assertOk();
        $entry = CashbookTransaction::query()->findOrFail($id)->ledgerEntry;
        $this->postJson('/api/v1/financial/cashbook-transactions/'.$id.'/reverse', ['reason' => 'Correction'])->assertCreated();
        $url = '/api/v1/financial/cashbook-ledger-entries/'.$entry->id.'/classify';
        FinancialPeriod::query()->whereKey($this->s['period']->id)->update(['state' => 'closed']);
        $this->postJson($url, ['report_treatment' => 'income', 'reason' => 'Verified sales cash receipt'])->assertUnprocessable();
        FinancialPeriod::query()->whereKey($this->s['period']->id)->update(['state' => 'open']);
        $this->postJson($url, ['report_treatment' => 'income', 'reason' => 'Verified sales cash receipt'])->assertOk();
        $this->assertSame('unclassified', $entry->fresh()->report_treatment);
        $this->assertDatabaseCount('cash_entry_classifications', 2);
        $this->getJson('/api/v1/financial/reports/cash-result')->assertOk()->assertJsonPath('data.is_complete', true)->assertJsonPath('data.currencies.0.income', '0.00');
        $this->postJson('/api/v1/financial/periods/'.$this->s['period']->id.'/close', ['reason' => 'Verified and classified'])->assertOk();
    }

    public function test_missing_branch_policy_blocks_posting_and_drafts_can_be_cancelled_with_audited_reason(): void
    {
        $id = $this->draft('2.00');
        FinancialBranchSetting::query()->where('branch_id', $this->s['branch']->id)->delete();
        $this->confirm($id)->assertUnprocessable()->assertJsonValidationErrors('financial_settings');
        $this->postJson('/api/v1/financial/cashbook-transactions/'.$id.'/cancel', ['reason' => 'Duplicate draft'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('cashbook_ledger_entries', 1);
        $this->assertDatabaseHas('activity_log', ['description' => 'cash_draft_cancelled']);
        $this->confirm($id)->assertUnprocessable();
    }
}
