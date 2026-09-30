<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DemoStaffAdvancePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'financial.advances.view',
            'financial.advances.create',
            'financial.advances.update',
            'financial.advances.confirm',
            'financial.advances.reverse',
            'financial.advance-repayments.create',
            'financial.advance-repayments.update',
            'financial.advance-repayments.confirm',
            'financial.advance-repayments.reverse',
            'financial.advance-repayments.cancel',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permissions);

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $admin?->givePermissionTo('financial.advances.view');
    }
}
