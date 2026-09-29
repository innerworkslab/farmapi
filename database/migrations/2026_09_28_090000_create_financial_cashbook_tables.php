<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('bank_reference')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->date('effective_date');
            $table->string('status', 16)->default('active')->index();
            $table->string('deactivation_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'type', 'normalized_name'], 'cashbooks_branch_type_name_unique');
            $table->index(['branch_id', 'status', 'type'], 'cashbooks_branch_status_type_idx');
        });

        Schema::create('cashbook_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->date('business_date');
            $table->string('direction', 8);
            $table->decimal('amount', 18, 2);
            $table->text('description');
            $table->string('source_type', 40)->default('manual');
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cashbook_id', 'external_reference'], 'cashbook_tx_cashbook_external_unique');
            $table->index(['cashbook_id', 'business_date', 'status'], 'cashbook_tx_book_date_status_idx');
        });

        Schema::create('cashbook_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashbook_id')->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->foreignId('reversal_of_entry_id')->nullable()->constrained('cashbook_ledger_entries')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->date('entry_date');
            $table->text('description');
            $table->string('source_type', 40);
            $table->string('direction', 8);
            $table->decimal('amount', 18, 2);
            $table->decimal('running_balance', 18, 2);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cashbook_id', 'entry_date', 'id'], 'cashbook_ledger_book_date_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbook_ledger_entries');
        Schema::dropIfExists('cashbook_transactions');
        Schema::dropIfExists('cashbooks');
    }
};
