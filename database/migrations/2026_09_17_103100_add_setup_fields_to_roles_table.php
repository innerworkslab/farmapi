<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('guard_name')->constrained('branches')->nullOnDelete();
            $table->string('status')->default('active')->after('branch_id')->index();
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropColumn(['branch_id', 'status', 'version']);
        });
    }
};
