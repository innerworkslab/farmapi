<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
        'purchasing.view',
        'purchasing.manage',
        'inventory.view',
        'inventory.manage',
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
            'purchasing.view',
            'inventory.view',
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
