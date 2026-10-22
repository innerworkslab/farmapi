<?php

namespace Database\Seeders;

use App\Modules\Setup\Models\Division;
use App\Modules\Setup\Models\Township;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MyanmarGeographySeeder extends Seeder
{
    public function run(): void
    {
        $divisions = File::json(database_path('seeders/data/myanmar/divisions.json'), JSON_THROW_ON_ERROR);
        $townships = File::json(database_path('seeders/data/myanmar/townships.json'), JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($divisions, $townships): void {
            $rows = fn (array $records): array => array_map(fn (array $record): array => [
                'id' => $record['id'],
                'name' => $record['name'],
                'status' => $record['is_active'] ? 'active' : 'inactive',
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ], $records);

            // Keep existing status and soft deletion choices when reimporting source names.
            Division::query()->upsert($rows($divisions), ['id'], ['name', 'updated_at']);
            $townshipRows = array_map(fn (array $row, array $source): array => $row + [
                'division_id' => $source['division_id'],
            ], $rows($townships), $townships);
            foreach (array_chunk($townshipRows, 100) as $chunk) {
                Township::query()->upsert($chunk, ['id'], ['division_id', 'name', 'updated_at']);
            }
        });
    }
}
