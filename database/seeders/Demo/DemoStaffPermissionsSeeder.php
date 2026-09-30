<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DemoStaffPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'setup.staff.view',
            'setup.staff.create',
            'setup.staff.update',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permissions);

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $admin?->givePermissionTo($permissions);
    }
}
