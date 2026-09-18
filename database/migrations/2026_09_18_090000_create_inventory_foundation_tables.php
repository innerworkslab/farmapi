<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->index();
            $table->string('item_type')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('farm_information_id')->nullable()->constrained('farm_information')->nullOnDelete();
            $table->string('location')->default('MAIN');
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_batch_number')->nullable();
            $table->string('receipt_lot_number')->nullable()->unique();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('lot_status')->default('available')->index();
            $table->boolean('cold_chain_required')->default(false);
            $table->decimal('min_temperature', 8, 3)->nullable();
            $table->decimal('max_temperature', 8, 3)->nullable();
            $table->decimal('observed_temperature', 8, 3)->nullable();
            $table->string('temperature_uom')->nullable();
            $table->string('cold_chain_status')->nullable();
            $table->text('restriction_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'item_id', 'inventory_id'], 'inv_lots_cat_item_inv_idx');
            $table->index(['inventory_id', 'location', 'lot_status'], 'inv_lots_inv_loc_status_idx');
            $table->index(['expiry_date', 'lot_status'], 'inv_lots_exp_status_idx');
        });

        Schema::create('inventory_equipment_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('farm_information_id')->nullable()->constrained('farm_information')->nullOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('inventory_stock_lots')->nullOnDelete();
            $table->string('location')->default('MAIN');
            $table->string('serial_number')->nullable()->unique();
            $table->string('asset_tag')->nullable()->unique();
            $table->string('condition')->default('good');
            $table->string('lifecycle_status')->default('available')->index();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['equipment_id', 'inventory_id', 'lifecycle_status'], 'inv_eq_inst_eq_inv_status_idx');
            $table->index(['inventory_id', 'location'], 'inv_eq_inst_inv_loc_idx');
        });

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_key')->unique();
            $table->string('category')->index();
            $table->string('item_type')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('farm_information_id')->nullable()->constrained('farm_information')->nullOnDelete();
            $table->string('location')->default('MAIN');
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('inventory_stock_lots')->nullOnDelete();
            $table->foreignId('equipment_instance_id')->nullable()->constrained('inventory_equipment_instances')->nullOnDelete();
            $table->decimal('on_hand_quantity', 18, 6)->default(0);
            $table->decimal('reserved_quantity', 18, 6)->default(0);
            $table->decimal('quarantined_quantity', 18, 6)->default(0);
            $table->decimal('damaged_quantity', 18, 6)->default(0);
            $table->decimal('expired_quantity', 18, 6)->default(0);
            $table->decimal('available_quantity', 18, 6)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('last_ledger_entry_id')->nullable();
            $table->timestamps();

            $table->index(['category', 'item_id', 'inventory_id'], 'inv_bal_cat_item_inv_idx');
            $table->index(['inventory_id', 'location'], 'inv_bal_inv_loc_idx');
            $table->index(['stock_lot_id', 'equipment_instance_id'], 'inv_bal_lot_inst_idx');
        });

        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->string('type')->index();
            $table->date('adjustment_date');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('farm_information_id')->nullable()->constrained('farm_information')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->string('reason_type')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('posting_batch_id')->nullable()->unique();
            $table->foreignId('original_adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inventory_id', 'status']);
            $table->index(['adjustment_date', 'status']);
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('inventory_adjustments')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('category')->index();
            $table->string('item_type')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('location')->default('MAIN');
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('inventory_stock_lots')->nullOnDelete();
            $table->foreignId('equipment_instance_id')->nullable()->constrained('inventory_equipment_instances')->nullOnDelete();
            $table->decimal('system_quantity', 18, 6)->default(0);
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('adjustment_quantity', 18, 6);
            $table->string('direction');
            $table->boolean('new_identity')->default(false);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_batch_number')->nullable();
            $table->string('receipt_lot_number')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('lot_status')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('asset_tag')->nullable();
            $table->string('condition')->nullable();
            $table->string('lifecycle_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['adjustment_id', 'line_number'], 'inv_adj_lines_adj_line_unique');
            $table->index(['category', 'item_id'], 'inv_adj_lines_cat_item_idx');
            $table->index(['stock_lot_id', 'equipment_instance_id'], 'inv_adj_lines_lot_inst_idx');
        });

        Schema::create('inventory_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->string('confirmation_number')->unique();
            $table->string('source_module')->default('inventory');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('submission_version')->default(1);
            $table->string('status')->default('pending')->index();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('posting_batch_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('payload_snapshot')->nullable();
            $table->json('validation_snapshot')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'inv_src_type_id_idx');
            $table->index(['source_module', 'status'], 'inv_cnf_module_status_idx');
        });

        Schema::create('inventory_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('posting_id')->unique();
            $table->string('posting_batch_id')->index();
            $table->foreignId('confirmation_id')->nullable()->constrained('inventory_confirmations')->nullOnDelete();
            $table->string('source_module')->default('inventory');
            $table->string('source_type')->index();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('transaction_type')->index();
            $table->string('direction');
            $table->string('identity_key')->index();
            $table->string('category')->index();
            $table->string('item_type')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('farm_information_id')->nullable()->constrained('farm_information')->nullOnDelete();
            $table->string('location')->default('MAIN');
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('inventory_stock_lots')->nullOnDelete();
            $table->foreignId('equipment_instance_id')->nullable()->constrained('inventory_equipment_instances')->nullOnDelete();
            $table->decimal('original_quantity', 18, 6);
            $table->foreignId('original_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 8)->default(1);
            $table->decimal('quantity_in', 18, 6)->default(0);
            $table->decimal('quantity_out', 18, 6)->default(0);
            $table->decimal('balance_before', 18, 6)->default(0);
            $table->decimal('balance_after', 18, 6)->default(0);
            $table->string('posting_status')->default('posted')->index();
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('original_ledger_entry_id')->nullable()->constrained('inventory_ledger_entries')->nullOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'inv_led_src_type_id_idx');
            $table->index(['inventory_id', 'posted_at'], 'inv_led_inv_posted_idx');
            $table->index(['category', 'item_id', 'posted_at'], 'inv_led_cat_item_posted_idx');
        });

        Schema::create('inventory_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->string('eventable_type');
            $table->unsignedBigInteger('eventable_id');
            $table->string('event_type')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['eventable_type', 'eventable_id'], 'inv_wf_eventable_idx');
            $table->index(['occurred_at', 'event_type'], 'inv_wf_time_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_workflow_events');
        Schema::dropIfExists('inventory_ledger_entries');
        Schema::dropIfExists('inventory_confirmations');
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_equipment_instances');
        Schema::dropIfExists('inventory_stock_lots');
    }
};
