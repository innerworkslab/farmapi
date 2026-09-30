<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->decimal('principal_amount', 18, 2);
            $table->char('currency_code', 3);
            $table->date('business_date');
            $table->foreignId('cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->text('description');
            $table->string('staff_code_snapshot', 50)->nullable();
            $table->string('staff_name_snapshot', 255)->nullable();
            $table->string('branch_code_snapshot', 50)->nullable();
            $table->string('branch_name_snapshot', 255)->nullable();
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('reversed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_id', 'status', 'business_date'], 'staff_advances_staff_status_date_idx');
            $table->index(['branch_id', 'status', 'business_date'], 'staff_advances_branch_status_date_idx');
        });

        Schema::create('staff_advance_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_advance_id')->constrained('staff_advances')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->decimal('amount', 18, 2);
            $table->date('business_date');
            $table->foreignId('cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->string('external_reference')->nullable();
            $table->text('description');
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('reversed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['staff_advance_id', 'status', 'business_date'], 'staff_advance_repayments_loan_status_date_idx');
            $table->index(['staff_id', 'status', 'business_date'], 'staff_advance_repayments_staff_status_date_idx');
        });

        Schema::create('staff_advance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('staff_advance_id')->constrained('staff_advances')->restrictOnDelete();
            $table->foreignId('staff_advance_repayment_id')->nullable()->constrained('staff_advance_repayments')->restrictOnDelete();
            $table->foreignId('cashbook_transaction_id')->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->foreignId('reversal_of_entry_id')->nullable()->unique()->constrained('staff_advance_ledger_entries')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->date('business_date');
            $table->string('entry_type', 32);
            $table->string('effect', 16);
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance', 18, 2);
            $table->char('currency_code', 3);
            $table->text('description');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'id'], 'staff_advance_ledger_staff_sequence_idx');
            $table->index(['staff_advance_id', 'id'], 'staff_advance_ledger_loan_sequence_idx');
            $table->index(['branch_id', 'business_date', 'id'], 'staff_advance_ledger_branch_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advance_ledger_entries');
        Schema::dropIfExists('staff_advance_repayments');
        Schema::dropIfExists('staff_advances');
    }
};
