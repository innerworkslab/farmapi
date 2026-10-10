<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->string('sale_number')->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('farm_information_id')->constrained('farm_information')->restrictOnDelete();
            $table->foreignId('inventory_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('sale_date');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('foc_quantity')->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->string('discount_type')->default('none');
            $table->decimal('discount_value', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('ar_amount', 18, 2)->default(0);
            $table->string('payment_status')->default('unpaid');
            $table->string('status')->default('draft')->index();
            $table->json('customer_snapshot')->nullable();
            $table->foreignId('cashbook_id')->nullable()->constrained('cashbooks')->restrictOnDelete();
            $table->foreignId('cash_category_id')->nullable()->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'sale_date', 'status'], 'sales_branch_date_status_idx');
            $table->index(['customer_id', 'payment_status'], 'sales_customer_payment_idx');
        });

        Schema::create('customer_receivable_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cashbook_transaction_id')->nullable()->unique()->constrained('cashbook_transactions')->restrictOnDelete();
            $table->string('entry_type');
            $table->decimal('amount', 18, 2);
            $table->string('reference');
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'created_at'], 'customer_receivable_customer_date_idx');
        });

        Schema::create('sale_sequences', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['branch_id', 'sequence_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_sequences');
        Schema::dropIfExists('customer_receivable_entries');
        Schema::dropIfExists('sales');
    }
};
