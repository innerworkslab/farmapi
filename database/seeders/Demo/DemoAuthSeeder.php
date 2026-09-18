<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Setup\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class DemoAuthSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        'authorization.permissions.view',
        'setup.view',
        'setup.manage',
        'setup.branches.view',
        'setup.branches.create',
        'setup.branches.update',
        'setup.branches.delete',
        'setup.roles.view',
        'setup.roles.create',
        'setup.roles.update',
        'setup.roles.delete',
        'setup.admins.view',
        'setup.admins.create',
        'setup.admins.update',
        'setup.admins.delete',
        'setup.audit.view',
        'setup.customers.view',
        'setup.customers.create',
        'setup.customers.update',
        'setup.customers.delete',
        'setup.suppliers.view',
        'setup.suppliers.create',
        'setup.suppliers.update',
        'setup.suppliers.delete',
        'setup.foods.view',
        'setup.foods.create',
        'setup.foods.update',
        'setup.foods.delete',
        'setup.medicines.view',
        'setup.medicines.create',
        'setup.medicines.update',
        'setup.medicines.delete',
        'setup.animals.view',
        'setup.animals.create',
        'setup.animals.update',
        'setup.animals.delete',
        'setup.equipment.view',
        'setup.equipment.create',
        'setup.equipment.update',
        'setup.equipment.delete',
        'setup.inventories.view',
        'setup.inventories.create',
        'setup.inventories.update',
        'setup.inventories.delete',
        'setup.uoms.view',
        'setup.uoms.create',
        'setup.uoms.update',
        'setup.uoms.delete',
        'setup.farm-information.view',
        'setup.farm-information.create',
        'setup.farm-information.update',
        'setup.farm-information.delete',
        'purchasing.view',
        'purchasing.manage',
        'inventory.view',
        'inventory.manage',
        'inventory.items.view',
        'inventory.balances.view',
        'inventory.ledger.view',
        'inventory.confirmations.view',
        'inventory.adjustments.view',
        'inventory.adjustments.create',
        'inventory.adjustments.update',
        'inventory.adjustments.submit',
        'inventory.adjustments.confirm',
        'inventory.adjustments.reverse',
        'inventory.history.export',
        'farms.view',
        'farms.manage',
        'sales.view',
        'sales.manage',
        'financial.view',
        'financial.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $salesOfficer = Role::findOrCreate('sales-officer', 'web');

        $superAdmin->syncPermissions($this->permissions);
        $admin->syncPermissions([
            'authorization.permissions.view',
            'setup.view',
            'setup.branches.view',
            'setup.roles.view',
            'setup.admins.view',
            'setup.audit.view',
            'setup.customers.view',
            'setup.customers.create',
            'setup.customers.update',
            'setup.customers.delete',
            'setup.suppliers.view',
            'setup.suppliers.create',
            'setup.suppliers.update',
            'setup.suppliers.delete',
            'setup.foods.view',
            'setup.foods.create',
            'setup.foods.update',
            'setup.foods.delete',
            'setup.medicines.view',
            'setup.medicines.create',
            'setup.medicines.update',
            'setup.medicines.delete',
            'setup.animals.view',
            'setup.animals.create',
            'setup.animals.update',
            'setup.animals.delete',
            'setup.equipment.view',
            'setup.equipment.create',
            'setup.equipment.update',
            'setup.equipment.delete',
            'setup.inventories.view',
            'setup.inventories.create',
            'setup.inventories.update',
            'setup.inventories.delete',
            'setup.uoms.view',
            'setup.uoms.create',
            'setup.uoms.update',
            'setup.uoms.delete',
            'setup.farm-information.view',
            'setup.farm-information.create',
            'setup.farm-information.update',
            'setup.farm-information.delete',
            'purchasing.view',
            'inventory.view',
            'inventory.manage',
            'inventory.items.view',
        'inventory.items.view',
            'inventory.balances.view',
            'inventory.ledger.view',
            'inventory.confirmations.view',
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.confirm',
            'inventory.adjustments.reverse',
            'farms.view',
            'sales.view',
            'financial.view',
        ]);
        $salesOfficer->syncPermissions([
            'sales.view',
            'sales.manage',
            'farms.view',
        ]);

        $this->createUser('Super Admin', 'superadmin@example.com', 'superadmin', $superAdmin);
        $this->createUser('Demo Admin', 'admin@example.com', 'admin', $admin);
        $this->createUser('Sales Officer', 'sales@example.com', 'sales', $salesOfficer);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUser(string $name, string $email, string $username, Role $role): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => $username,
                'password' => Hash::make('password'),
                'account_status' => 'active',
                'failed_login_count' => 0,
                'password_changed_at' => now(),
                'two_factor_enabled' => false,
            ]
        );

        $user->syncRoles([$role]);
    }
}