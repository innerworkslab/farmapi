<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_ledger_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('direction', 8);
            $table->foreignId('reversal_category_id')->nullable()->constrained('cash_ledger_categories')->restrictOnDelete();
            $table->string('status', 16)->default('active')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['direction', 'normalized_name'], 'cash_ledger_categories_direction_name_unique');
            $table->index(['direction', 'status', 'name'], 'cash_ledger_categories_direction_status_name_idx');
        });

        Schema::table('cashbook_transactions', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('direction')->constrained('cash_ledger_categories')->restrictOnDelete();
        });
        Schema::table('cashbook_ledger_entries', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('direction')->constrained('cash_ledger_categories')->restrictOnDelete();
        });

        $now = now();
        $cashInId = DB::table('cash_ledger_categories')->insertGetId([
            'name' => 'Uncategorized cash in',
            'normalized_name' => 'uncategorized cash in',
            'direction' => 'in',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $cashOutId = DB::table('cash_ledger_categories')->insertGetId([
            'name' => 'Uncategorized cash out',
            'normalized_name' => 'uncategorized cash out',
            'direction' => 'out',
            'reversal_category_id' => $cashInId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('cash_ledger_categories')->where('id', $cashInId)->update(['reversal_category_id' => $cashOutId]);

        DB::table('cashbook_transactions')->whereNull('category_id')->where('direction', 'in')->update(['category_id' => $cashInId]);
        DB::table('cashbook_transactions')->whereNull('category_id')->where('direction', 'out')->update(['category_id' => $cashOutId]);
        DB::table('cashbook_ledger_entries')->whereNull('category_id')->where('source_type', '<>', 'opening_balance')->where('direction', 'in')->update(['category_id' => $cashInId]);
        DB::table('cashbook_ledger_entries')->whereNull('category_id')->where('source_type', '<>', 'opening_balance')->where('direction', 'out')->update(['category_id' => $cashOutId]);
    }

    public function down(): void
    {
        Schema::table('cashbook_ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('cashbook_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('cash_ledger_categories');
    }
};
