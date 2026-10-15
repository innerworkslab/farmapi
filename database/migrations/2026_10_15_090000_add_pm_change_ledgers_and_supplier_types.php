<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_receivable_entries', function (Blueprint $table): void {
            $table->foreignId('sale_id')->nullable()->change();
            $table->date('business_date')->nullable()->index();
        });

        Schema::create('supplier_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->timestamps();
            $table->unique(['supplier_id', 'type']);
        });
        DB::table('suppliers')->orderBy('id')->each(function ($supplier): void {
            if (! empty($supplier->type)) {
                DB::table('supplier_types')->insert(['supplier_id' => $supplier->id, 'type' => $supplier->type, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Schema::create('supplier_payable_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3)->nullable();
            $table->date('business_date');
            $table->string('reference', 100);
            $table->string('idempotency_key')->unique();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'branch_id', 'business_date'], 'supp_payable_supp_branch_date_idx');
        });

        DB::table('customers')->where('opening_balance', '>', 0)->whereNotNull('preferred_branch_id')->orderBy('id')->each(function ($customer): void {
            DB::table('customer_receivable_entries')->insert([
                'customer_id' => $customer->id, 'sale_id' => null, 'branch_id' => $customer->preferred_branch_id,
                'entry_type' => 'opening_balance', 'amount' => $customer->opening_balance,
                'reference' => 'CUSTOMER-OPENING-'.$customer->id, 'idempotency_key' => 'customer-'.$customer->id.'-opening-balance',
                'business_date' => now()->toDateString(), 'created_by_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        DB::table('suppliers')->where('opening_balance', '>', 0)->whereNotNull('preferred_branch_id')->orderBy('id')->each(function ($supplier): void {
            if (($supplier->opening_balance_type ?? 'credit') === 'none') {
                return;
            }
            $amount = ($supplier->opening_balance_type ?? 'credit') === 'debit' ? '-'.$supplier->opening_balance : $supplier->opening_balance;
            DB::table('supplier_payable_entries')->insert([
                'supplier_id' => $supplier->id, 'branch_id' => $supplier->preferred_branch_id, 'entry_type' => 'opening_balance',
                'amount' => $amount, 'business_date' => now()->toDateString(), 'reference' => 'SUPPLIER-OPENING-'.$supplier->id,
                'idempotency_key' => 'supplier-'.$supplier->id.'-opening-balance', 'created_by_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payable_entries');
        Schema::dropIfExists('supplier_types');
        Schema::table('customer_receivable_entries', function (Blueprint $table): void {
            $table->dropIndex(['business_date']);
            $table->dropColumn('business_date');
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
