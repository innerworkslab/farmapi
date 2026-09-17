<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CUS-001..CUS-006: Customer Master fields from the Setup user-story sheet.
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone_number');
            $table->string('alternative_phone')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('township')->nullable();
            $table->string('state_region')->nullable();
            $table->foreignId('preferred_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('price_level')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['preferred_branch_id', 'status']);
            $table->index(['type', 'status']);
        });

        // SUP-001..SUP-006: Supplier Master fields from the Setup user-story sheet.
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone_number');
            $table->string('alternative_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('township')->nullable();
            $table->string('state_region')->nullable();
            $table->string('country')->nullable();
            $table->json('supplied_categories')->nullable();
            $table->foreignId('preferred_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->decimal('minimum_order_amount', 15, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->string('opening_balance_type')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['preferred_branch_id', 'status']);
            $table->index(['type', 'status']);
        });

        // UOM-001..UOM-006: UOM Master fields from the Setup user-story sheet.
        Schema::create('uoms', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('symbol');
            $table->string('category')->index();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // INV-001..INV-006: Inventory Master fields from the Setup user-story sheet.
        Schema::create('inventories', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->text('physical_address')->nullable();
            $table->string('building_zone')->nullable();
            $table->string('rack_bin')->nullable();
            $table->json('allowed_item_categories')->nullable();
            $table->string('inventory_gl_account')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
            $table->index(['type', 'status']);
        });

        // FOD-001..FOD-006: Food Master fields from the Setup user-story sheet.
        Schema::create('foods', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('type')->nullable();
            $table->string('target_animal_type');
            $table->string('life_stage')->nullable();
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('feed_form')->nullable();
            $table->foreignId('purchase_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('consumption_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('uom_conversion', 15, 6)->default(1);
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->boolean('batch_tracking')->default(true);
            $table->boolean('expiry_tracking')->default(true);
            $table->decimal('minimum_stock_level', 15, 3)->default(0);
            $table->decimal('maximum_stock_level', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->decimal('reorder_quantity', 15, 3)->default(0);
            $table->string('inventory_account')->nullable();
            $table->string('feed_expense_account')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category', 'status']);
            $table->index(['target_animal_type', 'status']);
        });

        // MED-001..MED-006: Medicine Master fields from the Setup user-story sheet.
        Schema::create('medicines', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('generic_name')->nullable();
            $table->string('type');
            $table->string('category');
            $table->string('target_animal_type')->nullable();
            $table->string('target_disease')->nullable();
            $table->string('active_ingredient')->nullable();
            $table->string('strength')->nullable();
            $table->string('dosage_form')->nullable();
            $table->string('dosage_unit')->nullable();
            $table->decimal('recommended_dosage', 15, 6)->nullable();
            $table->string('dosage_frequency')->nullable();
            $table->string('manufacturer')->nullable();
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('registration_number')->nullable()->unique();
            $table->string('package_size')->nullable();
            $table->foreignId('purchase_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('usage_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('uom_conversion', 15, 6)->default(1);
            $table->string('barcode_sku')->nullable()->unique();
            $table->boolean('batch_tracking')->default(true);
            $table->boolean('expiry_tracking')->default(true);
            $table->boolean('cold_chain_required')->default(false);
            $table->string('storage_temperature')->nullable();
            $table->text('storage_instruction')->nullable();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('standard_cost', 15, 2)->default(0);
            $table->decimal('minimum_stock_level', 15, 3)->default(0);
            $table->decimal('maximum_stock_level', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->decimal('reorder_quantity', 15, 3)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('inventory_account')->nullable();
            $table->string('medicine_expense_account')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'status']);
            $table->index(['category', 'status']);
        });

        // ANI-001..ANI-006: Animal Master fields from the Setup user-story sheet.
        Schema::create('animals', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('tracking_type')->index();
            $table->string('ear_tag_rfid_number')->nullable()->unique();
            $table->string('batch_flock_number')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('type');
            $table->string('category');
            $table->string('breed');
            $table->string('gender');
            $table->string('color_marking')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'category']);
            $table->index(['breed', 'gender']);
        });

        // EQP-001..EQP-005: Equipment Master fields from the Setup user-story sheet.
        Schema::create('equipment', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->string('manufacturer')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('purchase_cost', 15, 2)->default(0);
            $table->string('attachment_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category', 'brand']);
        });

        // FRM-001..FRM-006: Farm Information fields from the Setup user-story sheet.
        Schema::create('farm_information', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('house_barn');
            $table->string('pen_cage_pond')->nullable();
            $table->foreignId('current_animal_id')->nullable()->constrained('animals')->nullOnDelete();
            $table->foreignId('responsible_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'name', 'house_barn', 'pen_cage_pond'], 'farm_location_unique');
            $table->index(['branch_id', 'house_barn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_information');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('animals');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('foods');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('uoms');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};