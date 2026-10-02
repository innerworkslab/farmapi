<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('asset_name');
            $table->string('asset_category', 120)->nullable();
            $table->decimal('asset_price', 18, 2);
            $table->decimal('monthly_amount', 18, 2);
            $table->decimal('total_posted_amount', 18, 2)->default(0);
            $table->char('currency_code', 3);
            $table->date('start_date');
            $table->date('next_posting_date')->nullable();
            $table->foreignId('cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'start_date'], 'depreciations_branch_status_start_idx');
            $table->index(['cashbook_id', 'status', 'next_posting_date'], 'depreciations_book_status_next_idx');
        });

        Schema::create('depreciation_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depreciation_id')->constrained('depreciations')->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('reversed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['depreciation_id', 'sequence_number'], 'depreciation_lines_depreciation_sequence_unique');
            $table->index(['depreciation_id', 'status', 'due_date'], 'depreciation_lines_depreciation_status_due_idx');
            $table->index(['status', 'due_date'], 'depreciation_lines_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedule_lines');
        Schema::dropIfExists('depreciations');
    }
};
