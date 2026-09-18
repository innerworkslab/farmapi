<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->morphs('itemable');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->string('master_category')->nullable()->index();
            $table->foreignId('stock_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->foreignId('usage_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->decimal('uom_conversion', 15, 6)->default(1);
            $table->boolean('batch_tracking')->default(false);
            $table->boolean('expiry_tracking')->default(false);
            $table->boolean('serial_tracking')->default(false);
            $table->boolean('asset_tracking')->default(false);
            $table->boolean('cold_chain_required')->default(false);
            $table->boolean('divisible_quantity')->default(true);
            $table->decimal('minimum_stock_level', 15, 3)->default(0);
            $table->decimal('maximum_stock_level', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->decimal('reorder_quantity', 15, 3)->default(0);
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('source_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['itemable_type', 'itemable_id']);
            $table->index(['category', 'status']);
            $table->index(['stock_uom_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
