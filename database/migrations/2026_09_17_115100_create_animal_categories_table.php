<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animal_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('animal_type_id')->constrained('animal_types')->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['animal_type_id', 'normalized_name']);
            $table->unique(['id', 'animal_type_id']);
            $table->index(['animal_type_id', 'status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animal_categories');
    }
};
