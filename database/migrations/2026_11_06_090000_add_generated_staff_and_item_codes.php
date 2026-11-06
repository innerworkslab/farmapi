<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropIndex('staff_branch_code_idx');
            $table->dropColumn('normalized_staff_code');
            $table->unique('staff_code');
        });

    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropUnique(['staff_code']);
            $table->string('normalized_staff_code', 50)->nullable()->unique();
            $table->index(['branch_id', 'normalized_staff_code'], 'staff_branch_code_idx');
        });
        DB::table('staff')->update(['normalized_staff_code' => DB::raw('LOWER(staff_code)')]);
    }
};
