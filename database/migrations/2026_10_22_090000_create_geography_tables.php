<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'name']);
        });

        Schema::create('townships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('division_id')->constrained('divisions')->restrictOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['division_id', 'status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('townships');
        Schema::dropIfExists('divisions');
    }
};
