<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->restrictOnDelete();
            $table->char('default_currency', 3);
            $table->json('enabled_currencies');
            $table->string('timezone', 64);
            $table->boolean('require_open_period')->default(true);
            $table->boolean('require_approval_limit')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('financial_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('from_date');
            $table->date('to_date');
            $table->string('state', 16)->default('open');
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->json('reconciliation_snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'from_date', 'to_date']);
        });
        Schema::create('financial_posting_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('maximum_amount', 18, 2);
            $table->boolean('allow_self_approval')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'user_id', 'currency_code'], 'financial_posting_limit_unique');
        });
        Schema::table('cash_ledger_categories', function (Blueprint $table): void {
            $table->string('report_treatment', 16)->default('unclassified');
        });
        Schema::table('cashbook_ledger_entries', function (Blueprint $table): void {
            $table->string('report_treatment', 16)->default('unclassified');
        });
        Schema::create('cash_entry_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashbook_ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->string('report_treatment', 16);
            $table->text('reason');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('purchase_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashbook_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->char('currency_code', 3);
            $table->date('business_date');
            $table->decimal('amount', 18, 2);
            $table->string('state', 16)->default('draft');
            $table->text('description');
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purchase_invoice_id', 'state']);
        });
        Schema::create('staff_payroll_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->string('payroll_reference', 120);
            $table->string('evidence_reference', 255);
            $table->text('verification_note')->nullable();
            $table->date('business_date');
            $table->decimal('amount', 18, 2);
            $table->char('currency_code', 3);
            $table->string('state', 16)->default('draft');
            $table->text('description');
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['staff_advance_id', 'payroll_reference'], 'payroll_recovery_loan_reference_unique');
        });
        Schema::create('cashbook_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('destination_cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('out_category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->foreignId('in_category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->foreignId('out_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->foreignId('in_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->char('currency_code', 3);
            $table->date('business_date');
            $table->decimal('amount', 18, 2);
            $table->string('state', 16)->default('draft');
            $table->text('description');
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'business_date']);
        });
        Schema::table('staff_advance_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('cashbook_transaction_id')->nullable()->change();
            $table->foreignId('payroll_recovery_id')->nullable()->constrained('staff_payroll_recoveries')->restrictOnDelete();
        });
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->char('currency_code', 3)->nullable();
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->char('currency_code', 3)->nullable();
        });
        // Only cashbook-linked historical sales have a verifiable currency; other records remain unresolved.
        foreach (DB::table('sales')->join('cashbooks', 'cashbooks.id', '=', 'sales.cashbook_id')->select('sales.id', 'cashbooks.currency_code')->get() as $sale) {
            DB::table('sales')->where('id', $sale->id)->update(['currency_code' => $sale->currency_code]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('cash_entry_classifications');
        Schema::dropIfExists('cashbook_transfers');
        Schema::table('staff_advance_ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payroll_recovery_id');
        });
        Schema::dropIfExists('staff_payroll_recoveries');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('currency_code'));
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn('currency_code'));
        Schema::table('cashbook_ledger_entries', fn (Blueprint $table) => $table->dropColumn('report_treatment'));
        Schema::table('cash_ledger_categories', fn (Blueprint $table) => $table->dropColumn('report_treatment'));
        Schema::dropIfExists('financial_posting_limits');
        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('financial_branch_settings');
    }
};
