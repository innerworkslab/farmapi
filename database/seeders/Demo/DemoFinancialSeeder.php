<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoFinancialSeeder extends Seeder
{
    public function run(): void
    {
        $branch = \App\Modules\Setup\Models\Branch::query()->where('code', 'BR-YGN')->first();
        if (! $branch) {
            return;
        }

        $actor = User::query()->where('email', 'superadmin@example.com')->first();
        foreach ([
            ['type' => 'cash', 'name' => 'Yangon Main Cash', 'balance' => '1500000.00'],
            ['type' => 'bank', 'name' => 'Yangon Operating Bank', 'balance' => '8000000.00'],
        ] as $definition) {
            DB::transaction(function () use ($branch, $actor, $definition): void {
                $normalizedName = mb_strtolower($definition['name']);
                $cashbook = Cashbook::query()->firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'type' => $definition['type'],
                        'normalized_name' => $normalizedName,
                    ],
                    [
                        'name' => $definition['name'],
                        'currency_code' => 'MMK',
                        'opening_balance' => $definition['balance'],
                        'effective_date' => now()->toDateString(),
                        'status' => Cashbook::STATUS_ACTIVE,
                        'created_by_id' => $actor?->id,
                    ]
                );

                if (! $cashbook->entries()->where('source_type', 'opening_balance')->exists()) {
                    $cashbook->entries()->create([
                        'reference' => 'CBO-DEMO-'.$branch->id.'-'.strtoupper($definition['type']),
                        'entry_date' => $cashbook->effective_date,
                        'description' => 'Demo opening balance',
                        'source_type' => 'opening_balance',
                        'direction' => 'in',
                        'amount' => $cashbook->opening_balance,
                        'running_balance' => $cashbook->opening_balance,
                        'created_by_id' => $actor?->id,
                    ]);
                }
            });
        }
    }
}
