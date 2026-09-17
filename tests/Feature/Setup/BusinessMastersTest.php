<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BusinessMastersTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_master_crud_status_toggle_and_soft_delete(): void
    {
        $this->actingWithPermissions($this->permissionsFor('customers'));
        $branch = Branch::query()->create([
            'code' => 'BR-CUS',
            'name' => 'Customer Branch',
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/v1/setup/customers', [
            'code' => 'CUS-TST',
            'type' => 'retail',
            'name' => 'Test Customer',
            'phone_number' => '+95 9 700000001',
            'preferred_branch_id' => $branch->id,
            'price_level' => 'standard',
            'payment_terms' => 'cash',
            'credit_limit' => 0,
            'opening_balance' => 0,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'CUS-TST')
            ->assertJsonPath('data.status', 'active');

        $customerId = $create->json('data.id');

        $this->getJson('/api/v1/setup/customers?search=Test&type=retail')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'CUS-TST');

        $this->postJson("/api/v1/setup/customers/{$customerId}", [
            'code' => 'CUS-TST',
            'type' => 'retail',
            'name' => 'Updated Customer',
            'phone_number' => '+95 9 700000001',
            'preferred_branch_id' => $branch->id,
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Customer')
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/setup/customers/{$customerId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->deleteJson("/api/v1/setup/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('message', 'Customer deleted successfully.');

        $this->assertSoftDeleted('customers', ['id' => $customerId]);
    }

    public function test_business_master_dependency_chain_can_be_created(): void
    {
        $this->actingWithPermissions(array_merge(
            $this->permissionsFor('suppliers'),
            $this->permissionsFor('uoms'),
            $this->permissionsFor('inventories'),
            $this->permissionsFor('foods'),
            $this->permissionsFor('medicines'),
            $this->permissionsFor('animals'),
            $this->permissionsFor('equipment'),
            $this->permissionsFor('farm-information'),
        ));

        $branch = Branch::query()->create([
            'code' => 'BR-BIZ',
            'name' => 'Business Branch',
            'status' => 'active',
        ]);

        $uom = $this->postJson('/api/v1/setup/uoms', [
            'code' => 'KG',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'category' => 'weight',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        $supplier = $this->postJson('/api/v1/setup/suppliers', [
            'code' => 'SUP-TST',
            'type' => 'food',
            'name' => 'Test Supplier',
            'phone_number' => '+95 9 800000001',
            'supplied_categories' => ['feed'],
            'preferred_branch_id' => $branch->id,
            'lead_time_days' => 2,
            'minimum_order_amount' => 0,
            'credit_limit' => 0,
            'opening_balance_type' => 'none',
            'opening_balance' => 0,
        ])->assertCreated()
            ->json('data.id');

        $this->postJson('/api/v1/setup/inventories', [
            'code' => 'INV-TST',
            'name' => 'Test Inventory',
            'type' => 'feed',
            'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'],
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id);

        $this->postJson('/api/v1/setup/foods', [
            'code' => 'FOD-TST',
            'name' => 'Test Feed',
            'category' => 'feed',
            'target_animal_type' => 'cattle',
            'purchase_uom_id' => $uom,
            'stock_uom_id' => $uom,
            'consumption_uom_id' => $uom,
            'uom_conversion' => 1,
            'default_supplier_id' => $supplier,
            'purchase_price' => 10,
            'batch_tracking' => true,
            'expiry_tracking' => true,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'FOD-TST');

        $this->postJson('/api/v1/setup/medicines', [
            'code' => 'MED-TST',
            'name' => 'Test Medicine',
            'type' => 'vaccine',
            'category' => 'injection',
            'purchase_uom_id' => $uom,
            'stock_uom_id' => $uom,
            'usage_uom_id' => $uom,
            'uom_conversion' => 1,
            'default_supplier_id' => $supplier,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'cold_chain_required' => false,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'MED-TST');

        $animal = $this->postJson('/api/v1/setup/animals', [
            'code' => 'ANI-TST',
            'tracking_type' => 'batch',
            'batch_flock_number' => 'BATCH-TST',
            'name' => 'Test Batch',
            'type' => 'cattle',
            'category' => 'beef',
            'breed' => 'Brahman',
            'gender' => 'mixed',
        ])->assertCreated()
            ->json('data.id');

        $this->postJson('/api/v1/setup/equipment', [
            'code' => 'EQP-TST',
            'name' => 'Test Scale',
            'category' => 'weighing',
            'supplier_id' => $supplier,
            'purchase_cost' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'EQP-TST');

        $farm = $this->postJson('/api/v1/setup/farm-information', [
            'name' => 'Test Farm House',
            'branch_id' => $branch->id,
            'house_barn' => 'Barn A',
            'pen_cage_pond' => 'Pen 1',
            'current_animal_id' => $animal,
        ]);

        $farm->assertCreated()
            ->assertJsonPath('data.name', 'Test Farm House')
            ->assertJsonPath('data.current_animal_id', $animal);
    }

    /** @return list<string> */
    private function permissionsFor(string $master): array
    {
        return [
            "setup.{$master}.view",
            "setup.{$master}.create",
            "setup.{$master}.update",
            "setup.{$master}.delete",
        ];
    }

    /** @param list<string> $permissions */
    private function actingWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }
}