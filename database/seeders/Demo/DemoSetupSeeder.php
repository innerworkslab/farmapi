<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use Illuminate\Database\Seeder;

class DemoSetupSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'code' => 'BR-YGN',
                'name' => 'Yangon Main Farm',
                'phone_number' => '+95 9 400000001',
                'address' => 'Main livestock operations compound',
                'status' => Branch::STATUS_ACTIVE,
            ],
            [
                'code' => 'BR-MDY',
                'name' => 'Mandalay Feed & Holding',
                'phone_number' => '+95 9 400000002',
                'address' => 'Feed storage and holding branch',
                'status' => Branch::STATUS_ACTIVE,
            ],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(['code' => $branch['code']], $branch);
        }

        $branchIds = Branch::query()->pluck('id')->all();

        User::query()
            ->whereIn('email', ['superadmin@example.com', 'admin@example.com', 'sales@example.com'])
            ->get()
            ->each(fn (User $user) => $user->branches()->syncWithoutDetaching($branchIds));
    }
}
