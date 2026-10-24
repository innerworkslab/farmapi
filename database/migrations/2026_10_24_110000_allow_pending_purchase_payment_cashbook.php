<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->foreignId('cashbook_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('purchase_payments')->whereNull('cashbook_id')->exists()) {
            throw new RuntimeException('Cannot make purchase payment cashbooks required while pending payments have no cashbook.');
        }

        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->foreignId('cashbook_id')->nullable(false)->change();
        });
    }
};
