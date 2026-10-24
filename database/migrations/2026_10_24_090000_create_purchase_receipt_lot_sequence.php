<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_lot_sequence', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
        DB::table('purchase_receipt_lot_sequence')->insert(['id' => 1, 'last_value' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lot_sequence');
    }
};
