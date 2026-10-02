<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->string('status', 16)->default('active')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('depreciations', function (Blueprint $table): void {
            $table->foreignId('asset_category_id')->nullable()->after('asset_name')->constrained('asset_categories')->restrictOnDelete();
        });

        if (Schema::hasColumn('depreciations', 'asset_category')) {
            $now = now();
            $names = DB::table('depreciations')
                ->select('asset_category')
                ->whereNotNull('asset_category')
                ->where('asset_category', '<>', '')
                ->distinct()
                ->pluck('asset_category');

            foreach ($names as $name) {
                $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $name)) ?? trim((string) $name));
                $categoryId = DB::table('asset_categories')->where('normalized_name', $normalized)->value('id');
                if (! $categoryId) {
                    $categoryId = DB::table('asset_categories')->insertGetId([
                        'name' => trim((string) $name),
                        'normalized_name' => $normalized,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('depreciations')->where('asset_category', $name)->update(['asset_category_id' => $categoryId]);
            }

            Schema::table('depreciations', function (Blueprint $table): void {
                $table->dropColumn('asset_category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('depreciations', function (Blueprint $table): void {
            $table->string('asset_category', 120)->nullable()->after('asset_name');
        });

        DB::table('depreciations')
            ->leftJoin('asset_categories', 'depreciations.asset_category_id', '=', 'asset_categories.id')
            ->whereNotNull('depreciations.asset_category_id')
            ->update(['depreciations.asset_category' => DB::raw('asset_categories.name')]);

        Schema::table('depreciations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asset_category_id');
        });

        Schema::dropIfExists('asset_categories');
    }
};
