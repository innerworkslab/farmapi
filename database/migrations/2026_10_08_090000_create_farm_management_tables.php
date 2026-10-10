<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_animal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('animal_balance_id')->unique()->constrained('inventory_balances')->restrictOnDelete();
            $table->unsignedInteger('initial_quantity');
            $table->unsignedInteger('death_quantity')->default(0);
            $table->unsignedInteger('affected_quantity')->default(0);
            $table->string('health_status')->default('healthy');
            $table->string('animal_state')->default('alive');
            $table->date('date_of_birth')->nullable();
            $table->decimal('weight', 18, 6)->nullable();
            $table->string('weight_uom')->nullable();
            $table->string('life_stage')->nullable();
            $table->date('withdrawal_end_date')->nullable();
            $table->json('bio_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('farm_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('farm_information_id')->constrained('farm_information')->restrictOnDelete();
            $table->date('activity_date')->index();
            $table->time('activity_time')->nullable();
            $table->string('workflow_state')->default('draft')->index();
            $table->string('idempotency_key')->unique();
            $table->json('payload');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('original_operation_id')->nullable()->constrained('farm_operations')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['farm_information_id', 'type', 'activity_date'], 'farm_ops_history_idx');
        });
        Schema::create('farm_operation_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('farm_operations')->restrictOnDelete();
            $table->foreignId('animal_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['operation_id', 'animal_balance_id']);
        });
        Schema::create('farm_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_information_id')->unique()->constrained('farm_information')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->string('timezone')->default('Asia/Yangon');
            $table->decimal('daily_mortality_threshold', 9, 4)->nullable();
            $table->decimal('cumulative_mortality_threshold', 9, 4)->nullable();
            $table->decimal('feed_variance_threshold', 9, 4)->nullable();
            $table->decimal('feed_wastage_threshold', 9, 4)->nullable();
            $table->decimal('sickness_threshold', 9, 4)->nullable();
            $table->unsignedInteger('expiry_warning_days')->default(30);
            $table->foreignId('veterinarian_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('farm_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_information_id')->constrained('farm_information')->restrictOnDelete();
            $table->foreignId('animal_balance_id')->nullable()->constrained('inventory_balances')->restrictOnDelete();
            $table->string('type');
            $table->date('alert_date');
            $table->string('alert_key')->unique();
            $table->json('details');
            $table->text('investigation')->nullable();
            $table->foreignId('investigated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('farm_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('farm_alerts')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['alert_id', 'recipient_id']);
        });
        Schema::create('farm_feeding_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_information_id')->constrained('farm_information')->restrictOnDelete();
            $table->date('plan_date');
            $table->foreignId('animal_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->foreignId('food_item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['farm_information_id', 'plan_date', 'animal_balance_id', 'food_item_id', 'stock_uom_id'], 'farm_feeding_plan_unique');
        });
        Schema::create('farm_feeding_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feeding_id')->constrained('farm_feeding_records')->restrictOnDelete();
            $table->foreignId('animal_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['feeding_id', 'animal_balance_id']);
        });
    }

    public function down(): void
    {
        foreach (['farm_feeding_targets', 'farm_feeding_plans', 'farm_notifications', 'farm_alerts', 'farm_settings', 'farm_operation_targets', 'farm_operations', 'farm_animal_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
