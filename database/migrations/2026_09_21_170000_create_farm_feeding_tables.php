<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_feeding_records', function (Blueprint $table): void {
            $table->id();
            $table->string('feeding_number')->unique();
            $table->date('feeding_date');
            $table->time('feeding_time')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('farm_information_id')->constrained('farm_information')->restrictOnDelete();
            $table->foreignId('animal_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->foreignId('animal_item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedBigInteger('animal_id')->nullable();
            $table->string('target_type')->index();
            $table->string('animal_type')->nullable();
            $table->string('breed')->nullable();
            $table->decimal('animal_count', 18, 6)->default(0);
            $table->string('location')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('posting_batch_id')->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_information_id', 'feeding_date']);
            $table->index(['animal_balance_id', 'status']);
        });

        Schema::create('farm_feeding_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feeding_record_id')->constrained('farm_feeding_records')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('food_item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('inventory_stock_lots')->restrictOnDelete();
            $table->string('source_location')->default('MAIN');
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('wastage_quantity', 18, 6)->default(0);
            $table->decimal('quantity_per_animal', 18, 6)->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['feeding_record_id', 'line_number'], 'farm_feed_lines_record_line_unique');
            $table->index(['food_item_id', 'inventory_id']);
            $table->index(['stock_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_feeding_lines');
        Schema::dropIfExists('farm_feeding_records');
    }
};