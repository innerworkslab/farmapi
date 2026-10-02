<?php

namespace Database\Seeders\Demo;

use App\Modules\Financial\Models\CashLedgerCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DemoDepreciationPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'financial.depreciations.view',
            'financial.depreciations.create',
            'financial.depreciations.update',
            'financial.depreciations.activate',
            'financial.depreciations.cancel',
            'financial.depreciations.post',
            'financial.depreciations.reverse',
            'financial.asset-categories.view',
            'financial.asset-categories.create',
            'financial.asset-categories.update',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permissions);

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $admin?->givePermissionTo(['financial.depreciations.view', 'financial.asset-categories.view']);

        $this->ensureDepreciationCategoryPair();
    }

    private function ensureDepreciationCategoryPair(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $cashIn = CashLedgerCategory::query()->firstOrCreate(
                ['direction' => CashLedgerCategory::DIRECTION_IN, 'normalized_name' => 'depreciation reversal'],
                [
                    'name' => 'Depreciation reversal',
                    'status' => CashLedgerCategory::STATUS_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $cashOut = CashLedgerCategory::query()->firstOrCreate(
                ['direction' => CashLedgerCategory::DIRECTION_OUT, 'normalized_name' => 'depreciation out'],
                [
                    'name' => 'Depreciation out',
                    'status' => CashLedgerCategory::STATUS_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $cashIn->reversal_category_id = $cashOut->id;
            $cashIn->status = CashLedgerCategory::STATUS_ACTIVE;
            $cashIn->save();

            $cashOut->reversal_category_id = $cashIn->id;
            $cashOut->status = CashLedgerCategory::STATUS_ACTIVE;
            $cashOut->save();
        });
    }
}
