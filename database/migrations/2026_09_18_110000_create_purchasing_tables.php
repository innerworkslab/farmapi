<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status')->default('open')->index();
            $table->string('delivery_status')->default('not_received')->index();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('total_expected_quantity', 18, 6)->default(0);
            $table->decimal('total_received_quantity', 18, 6)->default(0);
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'delivery_status'], 'pur_inv_branch_delivery_idx');
            $table->index(['supplier_id', 'invoice_date'], 'pur_inv_supplier_date_idx');
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('category')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 8)->default(1);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 18, 6)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->string('foc_type')->nullable();
            $table->decimal('foc_value', 18, 6)->default(0);
            $table->decimal('foc_quantity', 18, 6)->default(0);
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('total_expected_quantity', 18, 6)->default(0);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->decimal('received_foc_quantity', 18, 6)->default(0);
            $table->foreignId('target_inventory_id')->nullable()->constrained('inventories')->restrictOnDelete();
            $table->foreignId('target_farm_information_id')->nullable()->constrained('farm_information')->restrictOnDelete();
            $table->string('target_location')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['purchase_invoice_id', 'line_number'], 'pur_inv_lines_inv_line_unique');
            $table->index(['category', 'item_id'], 'pur_inv_lines_cat_item_idx');
            $table->index(['target_inventory_id', 'target_location'], 'pur_inv_lines_inv_loc_idx');
        });

        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('status')->default('draft')->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('posting_batch_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_invoice_id', 'status'], 'pur_rcpt_invoice_status_idx');
            $table->index(['receipt_date', 'status'], 'pur_rcpt_date_status_idx');
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_line_id')->constrained('purchase_invoice_lines')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('category')->index();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('accepted_quantity', 18, 6)->default(0);
            $table->decimal('accepted_foc_quantity', 18, 6)->default(0);
            $table->decimal('rejected_quantity', 18, 6)->default(0);
            $table->text('rejection_reason')->nullable();
            $table->foreignId('target_inventory_id')->nullable()->constrained('inventories')->restrictOnDelete();
            $table->foreignId('target_farm_information_id')->nullable()->constrained('farm_information')->restrictOnDelete();
            $table->string('target_location')->nullable();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 8)->default(1);
            $table->string('supplier_batch_number')->nullable();
            $table->string('receipt_lot_number')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('cold_chain_required')->default(false);
            $table->string('cold_chain_status')->nullable();
            $table->decimal('observed_temperature', 8, 3)->nullable();
            $table->string('temperature_uom')->nullable();
            $table->text('exception_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['purchase_receipt_id', 'line_number'], 'pur_rcpt_lines_rcpt_line_unique');
            $table->index(['purchase_invoice_line_id'], 'pur_rcpt_lines_inv_line_idx');
            $table->index(['category', 'item_id'], 'pur_rcpt_lines_cat_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
    }
};