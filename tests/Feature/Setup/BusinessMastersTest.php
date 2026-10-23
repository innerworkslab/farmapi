<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Sales\Models\CustomerReceivableEntry;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Services\AnimalClassificationService;
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
            ->assertJsonPath('data.code', 'CUS-000001')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.payment_terms');

        $customerId = $create->json('data.id');

        $this->getJson('/api/v1/setup/customers?search=Test&type=retail')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'CUS-000001');

        $this->postJson("/api/v1/setup/customers/{$customerId}", [
            'type' => 'retail',
            'name' => 'Updated Customer',
            'phone_number' => '+95 9 700000001',
            'preferred_branch_id' => $branch->id,
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Customer')
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/setup/customers/{$customerId}", [
            'code' => 'CUS-CLIENT-OVERRIDE',
            'type' => 'retail',
            'name' => 'Updated Customer',
            'phone_number' => '+95 9 700000001',
        ])->assertOk()->assertJsonPath('data.code', 'CUS-000001');

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

        auth()->user()->branches()->attach($branch->id);

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
            'credit_limit' => 0,
            'opening_balance' => 0,
        ])->assertCreated()
            ->json('data.id');

        $inventoryId = $this->postJson('/api/v1/setup/inventories', [
            'code' => 'INV-TST',
            'name' => 'Test Inventory',
            'type' => 'feed',
            'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'],
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->json('data.id');

        $this->postJson("/api/v1/setup/inventories/{$inventoryId}", [
            'code' => 'INV-TST', 'name' => 'Test Inventory', 'type' => 'feed', 'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'],
        ])->assertOk();
        $this->postJson('/api/v1/setup/inventories', [
            'code' => 'INV-BAD-RACK', 'name' => 'Other Inventory', 'type' => 'feed', 'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'], 'rack_bin' => 'NOT-REGISTERED',
        ])->assertUnprocessable()->assertJsonValidationErrors('rack_bin');

        $food = $this->postJson('/api/v1/setup/foods', [
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
            ->assertJsonPath('data.code', 'FOD-TST')
            ->assertJsonPath('data.unit_roles.base_unit.id', $uom)
            ->assertJsonPath('data.unit_roles.conversion_direction', 'conversion_unit_to_base_unit')
            ->json('data.id');

        $this->getJson("/api/v1/setup/foods/{$food}")
            ->assertOk()
            ->assertJsonPath('data.unit_roles.base_unit.id', $uom)
            ->assertJsonPath('data.unit_roles.conversion_unit.id', $uom);

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

        $classifications = app(AnimalClassificationService::class);
        $animalType = $classifications->save('animal-types', ['name' => 'cattle']);
        $animalCategory = $classifications->save('animal-categories', ['name' => 'beef', 'animal_type_id' => $animalType->id]);
        $animalBreed = $classifications->save('animal-breeds', ['name' => 'Brahman', 'animal_type_id' => $animalType->id]);
        $animal = $this->postJson('/api/v1/setup/animals', [
            'code' => 'ANI-TST',
            'tracking_type' => 'batch',
            'batch_flock_number' => 'BATCH-TST',
            'name' => 'Test Batch',
            'animal_type_id' => $animalType->id,
            'animal_category_id' => $animalCategory->id,
            'animal_breed_id' => $animalBreed->id,
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
            ...\Database\Seeders\FarmLocationSeeder::references($branch->id, 'Barn A', 'Pen 1'),
            'current_animal_id' => $animal,
        ]);

        $farm->assertCreated()
            ->assertJsonPath('data.name', 'Test Farm House')
            ->assertJsonPath('data.current_animal_id', $animal);
        $this->postJson('/api/v1/setup/farm-information', [
            'name' => 'Invalid Barn Choice', 'branch_id' => $branch->id, 'house_barn_id' => 999999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('house_barn_id');
    }

    public function test_supplier_types_are_multi_select_and_opening_balance_posts_to_currency_scoped_ap(): void
    {
        $this->actingWithPermissions($this->permissionsFor('suppliers'));
        $branch = Branch::query()->create(['code' => 'BR-SUP-OPEN', 'name' => 'Supplier Branch', 'status' => 'active']);
        auth()->user()->branches()->attach($branch->id);

        $this->postJson('/api/v1/setup/suppliers', [
            'code' => 'SUP-FIELDS-REMOVED', 'types' => ['food'], 'name' => 'Removed Fields Supplier',
            'phone_number' => '+95 9 800000018', 'minimum_order_amount' => 100, 'min_order_amount' => 100,
            'opening_balance_type' => 'none',
        ])->assertUnprocessable()->assertJsonValidationErrors(['minimum_order_amount', 'min_order_amount', 'opening_balance_type']);

        $supplier = $this->postJson('/api/v1/setup/suppliers', [
            'code' => 'SUP-MULTI', 'types' => ['food', 'medicine'], 'name' => 'Multi Category Supplier',
            'phone_number' => '+95 9 800000019', 'preferred_branch_id' => $branch->id,
            'opening_balance' => 125.50, 'opening_balance_currency_code' => 'MMK', 'opening_balance_date' => '2020-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'food')
            ->assertJsonPath('data.types', ['food', 'medicine'])
            ->assertJsonPath('data.opening_balance_type', 'credit')
            ->assertJsonPath('data.opening_balance_date', today()->toDateString())
            ->json('data.id');

        $this->getJson("/api/v1/setup/suppliers/{$supplier}")
            ->assertOk()
            ->assertJsonPath('data.types', ['food', 'medicine']);

        $this->postJson("/api/v1/setup/suppliers/{$supplier}", [
            'code' => 'SUP-MULTI', 'types' => ['food', 'medicine'], 'name' => 'Multi Category Supplier',
            'phone_number' => '+95 9 800000019', 'preferred_branch_id' => $branch->id,
            'opening_balance_type' => 'credit', 'opening_balance' => 125.50, 'opening_balance_currency_code' => 'MMK',
        ])->assertOk()->assertJsonPath('data.types', ['food', 'medicine']);
        $this->getJson('/api/v1/setup/suppliers?type=medicine')->assertOk()->assertJsonPath('data.0.id', $supplier);

        $this->getJson("/api/v1/setup/suppliers/{$supplier}/payables")
            ->assertOk()
            ->assertJsonPath('data.balances.0.currency_code', 'MMK')
            ->assertJsonPath('data.balances.0.opening_balance', '125.50')
            ->assertJsonPath('data.balances.0.outstanding_amount', '125.50');

        $adjustment = ['branch_id' => $branch->id, 'currency_code' => 'MMK', 'amount' => '-25.00', 'reason' => 'Correct opening balance', 'idempotency_key' => 'sup-open-adjust-1'];
        $this->postJson("/api/v1/setup/suppliers/{$supplier}/payables/adjustments", $adjustment)->assertCreated()->assertJsonPath('data.entry_type', 'adjustment');
        $this->postJson("/api/v1/setup/suppliers/{$supplier}/payables/adjustments", $adjustment)->assertCreated();
        $this->getJson("/api/v1/setup/suppliers/{$supplier}/payables")
            ->assertJsonPath('data.balances.0.outstanding_amount', '100.50');
    }

    public function test_customer_opening_balance_is_posted_as_current_date_receivable(): void
    {
        $this->actingWithPermissions($this->permissionsFor('customers'));
        $branch = Branch::query()->create(['code' => 'BR-CUS-OPEN', 'name' => 'Customer Branch', 'status' => 'active']);
        auth()->user()->branches()->attach($branch->id);
        $id = $this->postJson('/api/v1/setup/customers', [
            'type' => 'retail', 'name' => 'Opening Customer', 'phone_number' => '+95 9 700000019',
            'preferred_branch_id' => $branch->id, 'opening_balance' => 250, 'opening_balance_date' => '2020-01-01',
        ])->assertCreated()->assertJsonPath('data.opening_balance_date', today()->toDateString())->json('data.id');

        $this->assertDatabaseHas('customer_receivable_entries', [
            'customer_id' => $id, 'branch_id' => $branch->id, 'entry_type' => 'opening_balance',
            'amount' => '250.00', 'sale_id' => null,
        ]);
        $this->assertDatabaseCount('customer_receivable_entries', 1);

        $adjustment = ['branch_id' => $branch->id, 'amount' => '-50.00', 'reason' => 'Correct opening balance', 'idempotency_key' => 'cus-open-adjust-1'];
        $this->postJson("/api/v1/setup/customers/{$id}/receivables/adjustments", $adjustment)->assertCreated()->assertJsonPath('data.entry_type', 'adjustment');
        $this->postJson("/api/v1/setup/customers/{$id}/receivables/adjustments", $adjustment)->assertCreated();
        $this->assertDatabaseCount('customer_receivable_entries', 2);

        $foreignBranch = Branch::query()->create(['code' => 'BR-CUS-FOREIGN', 'name' => 'Other Customer Branch', 'status' => 'active']);
        CustomerReceivableEntry::query()->create([
            'customer_id' => $id, 'sale_id' => null, 'branch_id' => $foreignBranch->id,
            'entry_type' => 'adjustment', 'amount' => 100, 'reference' => 'Out of scope',
            'idempotency_key' => 'cus-open-adjust-foreign', 'business_date' => today(),
        ]);

        $this->getJson("/api/v1/setup/customers/{$id}/receivables")
            ->assertOk()
            ->assertJsonPath('data.customer_id', $id)
            ->assertJsonPath('data.outstanding_balance', '200.00')
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.entry_type', 'opening_balance')
            ->assertJsonPath('data.entries.1.amount', '-50.00');
    }

    public function test_customer_code_is_generated_in_order_and_client_code_is_ignored(): void
    {
        $this->actingWithPermissions($this->permissionsFor('customers'));
        $payload = ['type' => 'retail', 'name' => 'Generated Customer', 'phone_number' => '+95 9 700000020'];

        $first = $this->postJson('/api/v1/setup/customers', $payload)->assertCreated()->json('data.code');
        $second = $this->postJson('/api/v1/setup/customers', $payload)->assertCreated()->json('data.code');

        $this->assertSame('CUS-000001', $first);
        $this->assertSame('CUS-000002', $second);
        $this->postJson('/api/v1/setup/customers', $payload + ['code' => 'CUS-CLIENT'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CUS-000003');
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
