<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoFinancialControlPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'financial.controls.view', 'financial.controls.manage', 'financial.periods.close', 'financial.periods.reopen',
        'financial.reconciliation.view', 'financial.transactions.backdate', 'financial.reports.detail', 'financial.reports.export',
        'purchasing.payments.view', 'purchasing.payments.create', 'purchasing.payments.confirm', 'purchasing.payments.reverse',
        'purchasing.invoices.confirm',
        'financial.payroll-recoveries.create', 'financial.payroll-recoveries.confirm', 'financial.payroll-recoveries.reverse',
        'financial.transfers.view', 'financial.transfers.create', 'financial.transfers.confirm', 'financial.transfers.reverse',
        'financial.reports.classify',
        'financial.transactions.cancel',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
