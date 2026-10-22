<?php

namespace Database\Seeders;

use App\Modules\Setup\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AnimalClassificationPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [];
        foreach (['animal_types', 'animal_categories', 'animal_breeds'] as $kind) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $name = 'setup.'.$kind.'.'.$action;
                Permission::findOrCreate($name, 'web');
                $permissions[] = $name;
            }
        }
        Role::where('name', 'super-admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
