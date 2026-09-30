<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->string('staff_code', 50);
            $table->string('normalized_staff_code', 50)->unique();
            $table->string('name', 255);
            $table->string('phone_number', 50)->nullable();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('employment_status', 20)->default('employed')->index();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'employment_status', 'name'], 'staff_branch_status_employment_name_idx');
            $table->index(['branch_id', 'normalized_staff_code'], 'staff_branch_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
