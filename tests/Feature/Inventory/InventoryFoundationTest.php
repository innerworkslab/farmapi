<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_confirmation_posts_ledger_and_current_balance(): void
    {
        $this->actingWithInventoryPermissions();
        $setup = $this->setupFoodInventory();

        $create = $this->postJson('/api/v1/inventory/adjustments', [
            'type' => 'opening_balance',
            'adjustment_date' => '2026-09-18',
            'branch_id' => $setup['branch']->id,
            'inventory_id' => $setup['inventory']->id,
            'reason_type' => 'opening_balance',
            'reason' => 'Initial count',
            'lines' => [[
                'category' => 'food',
                'item_id' => $setup['item']->id,
                'location' => 'R1',
                'stock_uom_id' => $setup['kg']->id,
                'adjustment_quantity' => 500,
                'direction' => 'in',
                'new_identity' => true,
                'supplier_id' => $setup['supplier']->id,
                'supplier_batch_number' => 'SUP-B001',
                'receipt_lot_number' => 'LOT-001',
                'manufacturing_date' => '2026-08-01',
                'expiry_date' => '2027-08-01',
                'lot_status' => 'available',
            ]],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $adjustmentId = $create->json('data.id');
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);

        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseCount('inventory_balances', 0);

        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseCount('inventory_ledger_entries', 1);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'source_type' => 'adjustment',
            'transaction_type' => 'opening_balance',
            'direction' => 'in',
            'quantity_in' => 500,
            'balance_before' => 0,
            'balance_after' => 500,
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'category' => 'food',
            'item_id' => $setup['item']->id,
            'inventory_id' => $setup['inventory']->id,
            'on_hand_quantity' => 500,
            'available_quantity' => 500,
        ]);

        $this->getJson('/api/v1/inventory/balances?category=food')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 500);
    }

    public function test_confirmation_revalidates_and_blocks_negative_stock(): void
    {
        $this->actingWithInventoryPermissions();
        $setup = $this->setupFoodInventory();
        $lot = $this->postOpeningBalance($setup, 10);

        $create = $this->postJson('/api/v1/inventory/adjustments', [
            'type' => 'data_correction',
            'adjustment_date' => '2026-09-18',
            'branch_id' => $setup['branch']->id,
            'inventory_id' => $setup['inventory']->id,
            'reason_type' => 'correction',
            'reason' => 'Attempt too much stock out',
            'lines' => [[
                'category' => 'food',
                'item_id' => $setup['item']->id,
                'location' => 'R1',
                'stock_uom_id' => $setup['kg']->id,
                'stock_lot_id' => $lot->id,
                'adjustment_quantity' => 15,
                'direction' => 'out',
            ]],
        ])->assertCreated();

        $adjustmentId = $create->json('data.id');
        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/submit")->assertOk();
        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertSame(1, InventoryLedgerEntry::query()->count());
        $this->assertEquals(10, InventoryBalance::query()->firstOrFail()->available_quantity);
    }

    public function test_setup_food_create_auto_registers_inventory_item(): void
    {
        $this->actingWithPermissions([
            'setup.foods.create',
            'setup.foods.view',
            'inventory.items.view',
        ]);

        $setup = $this->setupFoodInventory(false);

        $create = $this->postJson('/api/v1/setup/foods', [
            'code' => 'FOD-AUTO',
            'name' => 'Auto Registered Feed',
            'category' => 'feed',
            'target_animal_type' => 'cattle',
            'purchase_uom_id' => $setup['kg']->id,
            'stock_uom_id' => $setup['kg']->id,
            'consumption_uom_id' => $setup['kg']->id,
            'uom_conversion' => 1,
            'default_supplier_id' => $setup['supplier']->id,
            'purchase_price' => 1,
            'batch_tracking' => true,
            'expiry_tracking' => true,
        ])->assertCreated();

        $foodId = $create->json('data.id');
        $this->assertDatabaseHas('items', [
            'itemable_type' => Food::class,
            'itemable_id' => $foodId,
            'code' => 'FOD-AUTO',
            'category' => 'food',
            'stock_uom_id' => $setup['kg']->id,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'status' => 'active',
        ]);

        $item = Item::query()->where('code', 'FOD-AUTO')->firstOrFail();
        $this->getJson('/api/v1/inventory/items?search=FOD-AUTO')
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.itemable_id', $foodId);
    }

    /** @return array<string, mixed> */
    private function setupFoodInventory(bool $createFood = true): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-INV',
            'name' => 'Inventory Branch',
            'status' => 'active',
        ]);
        $kg = Uom::query()->create([
            'code' => 'KG',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'category' => 'weight',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-INV',
            'type' => 'food',
            'name' => 'Inventory Supplier',
            'phone_number' => '+95 9 700000001',
            'supplied_categories' => ['feed'],
            'preferred_branch_id' => $branch->id,
            'lead_time_days' => 1,
            'minimum_order_amount' => 0,
            'credit_limit' => 0,
            'opening_balance_type' => 'none',
            'opening_balance' => 0,
            'status' => 'active',
        ]);
        $inventory = Inventory::query()->create([
            'code' => 'INV-FOOD',
            'name' => 'Food Store',
            'type' => 'feed',
            'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'],
            'status' => 'active',
        ]);

        if (! $createFood) {
            return compact('branch', 'kg', 'supplier', 'inventory');
        }

        $food = Food::query()->create([
            'code' => 'FOD-INV',
            'name' => 'Inventory Feed',
            'category' => 'feed',
            'target_animal_type' => 'cattle',
            'purchase_uom_id' => $kg->id,
            'stock_uom_id' => $kg->id,
            'consumption_uom_id' => $kg->id,
            'uom_conversion' => 1,
            'default_supplier_id' => $supplier->id,
            'purchase_price' => 1,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'status' => 'active',
        ]);
        $item = app(ItemRegistryService::class)->syncFromMaster($food);

        return compact('branch', 'kg', 'supplier', 'inventory', 'food', 'item');
    }

    /** @param array<string, mixed> $setup */
    private function postOpeningBalance(array $setup, int $quantity): StockLot
    {
        $create = $this->postJson('/api/v1/inventory/adjustments', [
            'type' => 'opening_balance',
            'adjustment_date' => '2026-09-18',
            'branch_id' => $setup['branch']->id,
            'inventory_id' => $setup['inventory']->id,
            'reason_type' => 'opening_balance',
            'reason' => 'Initial count',
            'lines' => [[
                'category' => 'food',
                'item_id' => $setup['item']->id,
                'location' => 'R1',
                'stock_uom_id' => $setup['kg']->id,
                'adjustment_quantity' => $quantity,
                'direction' => 'in',
                'new_identity' => true,
                'supplier_id' => $setup['supplier']->id,
                'supplier_batch_number' => 'SUP-B001',
                'receipt_lot_number' => 'LOT-OPENING',
                'manufacturing_date' => '2026-08-01',
                'expiry_date' => '2027-08-01',
                'lot_status' => 'available',
            ]],
        ])->assertCreated();

        $adjustmentId = $create->json('data.id');
        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/submit")->assertOk();
        $this->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/confirm")->assertOk();

        return StockLot::query()->where('receipt_lot_number', 'LOT-OPENING')->firstOrFail();
    }

    private function actingWithInventoryPermissions(): User
    {
        return $this->actingWithPermissions([
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
        ]);
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