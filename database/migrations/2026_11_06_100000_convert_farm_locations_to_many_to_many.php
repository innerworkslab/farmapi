<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_information', function (Blueprint $table): void {
            $table->unique(['id', 'branch_id'], 'farm_information_id_branch_unique');
        });

        $this->createPivot('farm_information_house_barn', 'house_barn_id', 'house_barns', 'farm_barn');
        $this->createPivot('farm_information_pen_cage_pond', 'pen_cage_pond_id', 'pen_cage_ponds', 'farm_pen');

        DB::table('farm_information')->whereNotNull('house_barn_id')->orderBy('id')->each(function (object $farm): void {
            DB::table('farm_information_house_barn')->insert([
                'farm_information_id' => $farm->id,
                'house_barn_id' => $farm->house_barn_id,
                'branch_id' => $farm->branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        DB::table('farm_information')->whereNotNull('pen_cage_pond_id')->orderBy('id')->each(function (object $farm): void {
            DB::table('farm_information_pen_cage_pond')->insert([
                'farm_information_id' => $farm->id,
                'pen_cage_pond_id' => $farm->pen_cage_pond_id,
                'branch_id' => $farm->branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('farm_information', function (Blueprint $table): void {
            $table->dropForeign('farm_barn_branch_foreign');
            $table->dropForeign('farm_pen_branch_foreign');
            $table->dropUnique('farm_location_unique');
            $table->dropIndex('farm_information_branch_id_house_barn_id_index');
            $table->dropColumn(['house_barn_id', 'pen_cage_pond_id']);
        });
    }

    public function down(): void
    {
        Schema::table('farm_information', function (Blueprint $table): void {
            $table->unsignedBigInteger('house_barn_id')->nullable();
            $table->unsignedBigInteger('pen_cage_pond_id')->nullable();
        });

        DB::table('farm_information_house_barn')->orderBy('farm_information_id')->orderBy('house_barn_id')->each(function (object $link): void {
            DB::table('farm_information')->where('id', $link->farm_information_id)->whereNull('house_barn_id')->update(['house_barn_id' => $link->house_barn_id]);
        });
        DB::table('farm_information_pen_cage_pond')->orderBy('farm_information_id')->orderBy('pen_cage_pond_id')->each(function (object $link): void {
            DB::table('farm_information')->where('id', $link->farm_information_id)->whereNull('pen_cage_pond_id')->update(['pen_cage_pond_id' => $link->pen_cage_pond_id]);
        });

        Schema::dropIfExists('farm_information_house_barn');
        Schema::dropIfExists('farm_information_pen_cage_pond');
        Schema::table('farm_information', function (Blueprint $table): void {
            $table->dropUnique('farm_information_id_branch_unique');
            $table->foreign(['house_barn_id', 'branch_id'], 'farm_barn_branch_foreign')->references(['id', 'branch_id'])->on('house_barns')->restrictOnDelete();
            $table->foreign(['pen_cage_pond_id', 'branch_id'], 'farm_pen_branch_foreign')->references(['id', 'branch_id'])->on('pen_cage_ponds')->restrictOnDelete();
            $table->unique(['branch_id', 'name', 'house_barn_id', 'pen_cage_pond_id'], 'farm_location_unique');
            $table->index(['branch_id', 'house_barn_id']);
        });
    }

    private function createPivot(string $tableName, string $locationColumn, string $locationTable, string $prefix): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($locationColumn, $locationTable, $prefix): void {
            $table->unsignedBigInteger('farm_information_id');
            $table->unsignedBigInteger($locationColumn);
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->unique(['farm_information_id', $locationColumn], $prefix.'_pair_unique');
            $table->index([$locationColumn, 'branch_id'], $prefix.'_location_branch_idx');
            $table->foreign(['farm_information_id', 'branch_id'], $prefix.'_farm_branch_fk')
                ->references(['id', 'branch_id'])->on('farm_information')->cascadeOnDelete();
            $table->foreign([$locationColumn, 'branch_id'], $prefix.'_location_branch_fk')
                ->references(['id', 'branch_id'])->on($locationTable)->cascadeOnDelete();
        });
    }
};
