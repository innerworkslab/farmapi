<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pen_cage_ponds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('house_barn_id')->nullable();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['house_barn_id', 'branch_id'], 'pen_barn_branch_foreign')->references(['id', 'branch_id'])->on('house_barns')->restrictOnDelete();
            $table->unique(['branch_id', 'normalized_name']);
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pen_cage_ponds');
    }
};
