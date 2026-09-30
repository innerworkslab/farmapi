<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DemoFinancialCategoryPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'financial.categories.view',
            'financial.categories.create',
            'financial.categories.update',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permissions);

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $admin?->givePermissionTo('financial.categories.view');
    }
}
