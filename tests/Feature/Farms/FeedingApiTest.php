<?php

namespace Tests\Feature\Farms;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Inventory\Services\StockIdentityService;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeedingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_feeding_confirmation_rechecks_source_inventory_branch(): void
    {
        $user = $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(100);
        $user->branches()->attach($setup['branch']->id);
        $id = $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($setup, 1, 0))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertOk();
        $other = Branch::query()->create(['code' => 'MOVED', 'name' => 'Moved', 'status' => 'active']);
        $setup['inventory']->update(['branch_id' => $other->id]);
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertForbidden();
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->assertDatabaseHas('farm_feeding_records', ['id' => $id, 'status' => 'submitted']);
    }

    public function test_branch_membership_does_not_replace_action_permission(): void
    {
        $user = $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(100);
        $user->branches()->attach($setup['branch']->id);
        $user->revokePermissionTo('farms.manage');
        $this->getJson('/api/v1/farms/'.$setup['farm']->id)->assertOk();
        $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($setup, 1, 0))->assertForbidden();
        $this->assertDatabaseCount('farm_feeding_records', 0);
    }

    public function test_feeding_access_is_rechecked_for_lists_details_and_every_action(): void
    {
        $user = $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(100);
        $user->branches()->attach($setup['branch']->id);
        $payload = $this->feedingPayload($setup, 1, 0);
        $id = $this->postJson('/api/v1/farms/feedings', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertOk();
        foreach (['inventory.confirmations.view', 'inventory.ledger.view'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $confirmation = InventoryConfirmation::query()->where('source_type', 'feeding')->firstOrFail();
        $this->getJson('/api/v1/inventory/confirmations/'.$confirmation->id)->assertOk();
        $other = Branch::query()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $user->branches()->sync([$other->id]);
        $this->getJson('/api/v1/farms/feedings?farm_information_id='.$setup['farm']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/farms/feedings/'.$id)->assertForbidden();
        $this->getJson('/api/v1/inventory/confirmations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/confirmations/'.$confirmation->id)->assertNotFound();
        $this->postJson('/api/v1/farms/feedings', $payload)->assertForbidden();
        $this->postJson('/api/v1/farms/feedings/'.$id, $payload)->assertForbidden();
        foreach (['submit', 'confirm', 'reject'] as $action) {
            $this->postJson('/api/v1/farms/feedings/'.$id.'/'.$action, ['reason' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->assertDatabaseHas('farm_feeding_records', ['id' => $id, 'status' => 'submitted']);
        $user->branches()->detach();
        $this->getJson('/api/v1/farms/feedings')->assertOk()->assertJsonCount(0, 'data');
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->getJson('/api/v1/farms/feedings/'.$id)->assertOk();
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertOk();
    }

    public static function foodStockChoices(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('foodStockChoices')]
    public function test_feeding_converts_usage_quantity_and_wastage_and_reverses_in_stock_units(bool $stockInUsage): void
    {
        $user = $this->actingWithFarmPermissions();
        $initial = $stockInUsage ? 2000 : 2;
        $s = $this->setupFeedingStock($initial);
        $user->branches()->attach($s['branch']->id);
        $other = Uom::create(['code' => 'OTHER-UOM', 'name' => 'Other unit', 'symbol' => 'other', 'category' => 'weight', 'status' => 'active']);
        $purchaseId = $stockInUsage ? $other->id : $s['uom']->id;
        $usageId = $stockInUsage ? $s['uom']->id : $other->id;
        $master = $s['foodItem']->itemable;
        $master->update(['purchase_uom_id' => $purchaseId, 'consumption_uom_id' => $usageId, 'uom_conversion' => 1000, 'purchase_price' => '10.00']);
        app(ItemRegistryService::class)->syncFromMaster($master);
        $payload = $this->feedingPayload($s, 50, 5);
        $payload['lines'][0]['quantity_uom_id'] = $usageId;
        $quantity = $stockInUsage ? 50 : 0.05;
        $wastage = $stockInUsage ? 5 : 0.005;
        $id = $this->postJson('/api/v1/farms/feedings', $payload)->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', $quantity)
            ->assertJsonPath('data.lines.0.wastage_quantity', $wastage)
            ->assertJsonPath('data.lines.0.metadata.original_uom_id', $usageId)->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertOk()
            ->assertJsonPath('data.lines.0.metadata.cost', '0.50');
        $this->assertEquals($initial - $quantity, $s['foodBalance']->fresh()->on_hand_quantity);
        $this->assertDatabaseHas('inventory_ledger_entries', ['item_id' => $s['foodItem']->id, 'original_quantity' => 50, 'original_uom_id' => $usageId, 'quantity_out' => $quantity]);
        $this->postJson('/api/v1/farms/feedings/'.$id.'/reverse', ['reason' => 'Correct unit test feeding'])->assertOk();
        $this->assertEquals($initial, $s['foodBalance']->fresh()->on_hand_quantity);

        $payload['lines'][0]['quantity'] = 3000;
        $id = $this->postJson('/api/v1/farms/feedings', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertUnprocessable();
        $this->assertEquals($initial, $s['foodBalance']->fresh()->on_hand_quantity);
    }

    public function test_feeding_confirmation_deducts_food_stock_and_posts_ledger(): void
    {
        $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(100);
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);

        $create = $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($setup, 12, 1.5));

        $create->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.target.target_type', 'batch')
            ->assertJsonPath('data.lines.0.quantity_per_animal', 0.3);

        $feedingId = $create->json('data.id');
        $this->assertEquals(100, InventoryBalance::query()->where('id', $setup['foodBalance']->id)->value('available_quantity'));
        $this->assertDatabaseCount('inventory_ledger_entries', 0);

        $this->postJson("/api/v1/farms/feedings/{$feedingId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.confirmation.status', 'pending');
        $this->assertEquals(100, InventoryBalance::query()->where('id', $setup['foodBalance']->id)->value('available_quantity'));

        $this->postJson("/api/v1/farms/feedings/{$feedingId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.confirmation.status', 'confirmed');

        $this->assertDatabaseHas('inventory_balances', [
            'id' => $setup['foodBalance']->id,
            'on_hand_quantity' => 88,
            'available_quantity' => 88,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'source_module' => 'farms',
            'source_type' => 'feeding',
            'source_id' => $feedingId,
            'transaction_type' => 'feed_consumption',
            'direction' => 'out',
            'quantity_out' => 12,
            'balance_before' => 100,
            'balance_after' => 88,
        ]);

        $this->getJson('/api/v1/farms/feedings?status=confirmed')
            ->assertOk()
            ->assertJsonPath('data.0.id', $feedingId);
    }

    public function test_feeding_submit_blocks_quantity_above_available_stock(): void
    {
        $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(5);
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);

        $create = $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($setup, 8, 0))
            ->assertCreated();

        $this->postJson('/api/v1/farms/feedings/'.$create->json('data.id').'/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity');

        $this->assertSame(0, InventoryLedgerEntry::query()->count());
        $this->assertEquals(5, InventoryBalance::query()->where('id', $setup['foodBalance']->id)->value('available_quantity'));
    }

    public function test_feeding_can_consume_general_food_stock_without_farm_balance_scope(): void
    {
        $this->actingWithFarmPermissions();
        $setup = $this->setupFeedingStock(925, true);
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);

        $create = $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($setup, 12, 1.5))
            ->assertCreated();

        $feedingId = $create->json('data.id');

        $this->postJson("/api/v1/farms/feedings/{$feedingId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->postJson("/api/v1/farms/feedings/{$feedingId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('inventory_balances', [
            'id' => $setup['foodBalance']->id,
            'farm_information_id' => null,
            'on_hand_quantity' => 913,
            'available_quantity' => 913,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'source_module' => 'farms',
            'source_type' => 'feeding',
            'source_id' => $feedingId,
            'transaction_type' => 'feed_consumption',
            'farm_information_id' => null,
            'quantity_out' => 12,
            'balance_before' => 925,
            'balance_after' => 913,
        ]);
    }

    /** @return array<string, mixed> */
    private function setupFeedingStock(int $foodQuantity, bool $generalFoodStock = false): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-FEED',
            'name' => 'Feed Branch',
            'status' => 'active',
        ]);
        $uom = Uom::query()->create([
            'code' => 'KG',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'category' => 'weight',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-FEED',
            'type' => 'food',
            'name' => 'Feed Supplier',
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
            'code' => 'INV-FEED',
            'name' => 'Feed Store',
            'type' => 'feed',
            'branch_id' => $branch->id,
            'allowed_item_categories' => ['feed'],
            'status' => 'active',
        ]);
        $animal = Animal::factory()->create([
            'code' => 'ANI-FEED-BATCH',
            'tracking_type' => 'batch',
            'batch_flock_number' => 'FEED-BATCH-001',
            'name' => 'Feeding Batch',
            'gender' => 'mixed',
        ]);
        $farm = FarmInformation::query()->create([
            'name' => 'Feed Barn',
            'branch_id' => $branch->id,
            'house_barn' => 'Barn F',
            'pen_cage_pond' => 'Pen F1',
            'current_animal_id' => $animal->id,
        ]);
        $food = Food::query()->create([
            'code' => 'FOD-FEED',
            'name' => 'Grower Feed',
            'category' => 'feed',
            'target_animal_type' => 'cattle',
            'purchase_uom_id' => $uom->id,
            'stock_uom_id' => $uom->id,
            'consumption_uom_id' => $uom->id,
            'uom_conversion' => 1,
            'default_supplier_id' => $supplier->id,
            'purchase_price' => 1,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'status' => 'active',
        ]);

        $registry = app(ItemRegistryService::class);
        $animalItem = $registry->syncFromMaster($animal);
        $foodItem = $registry->syncFromMaster($food);

        $animalBalance = InventoryBalance::query()->create([
            'identity_key' => 'FEED-ANIMAL-BALANCE-'.$foodQuantity,
            'category' => 'animal',
            'item_type' => 'animal',
            'item_id' => $animalItem->id,
            'branch_id' => $branch->id,
            'inventory_id' => $inventory->id,
            'farm_information_id' => $farm->id,
            'location' => 'Pen F1',
            'stock_uom_id' => $uom->id,
            'on_hand_quantity' => 40,
            'available_quantity' => 40,
        ]);
        $stockLot = StockLot::query()->create([
            'category' => 'food',
            'item_type' => 'food',
            'item_id' => $foodItem->id,
            'branch_id' => $branch->id,
            'inventory_id' => $inventory->id,
            'farm_information_id' => $generalFoodStock ? null : $farm->id,
            'location' => 'Pen F1',
            'stock_uom_id' => $uom->id,
            'supplier_id' => $supplier->id,
            'supplier_batch_number' => 'SUP-FEED-001',
            'receipt_lot_number' => 'LOT-FEED-'.$foodQuantity,
            'manufacturing_date' => '2026-09-01',
            'expiry_date' => '2027-09-01',
            'lot_status' => 'available',
        ]);
        $identity = [
            'category' => 'food',
            'item_type' => 'food',
            'item_id' => $foodItem->id,
            'branch_id' => $branch->id,
            'inventory_id' => $inventory->id,
            'farm_information_id' => $generalFoodStock ? null : $farm->id,
            'location' => 'Pen F1',
            'stock_uom_id' => $uom->id,
            'stock_lot_id' => $stockLot->id,
            'equipment_instance_id' => null,
        ];
        $foodBalance = InventoryBalance::query()->create($identity + [
            'identity_key' => app(StockIdentityService::class)->key($identity),
            'on_hand_quantity' => $foodQuantity,
            'available_quantity' => $foodQuantity,
        ]);

        return compact('branch', 'uom', 'inventory', 'farm', 'animalBalance', 'foodItem', 'stockLot', 'foodBalance');
    }

    /** @param array<string, mixed> $setup */
    private function feedingPayload(array $setup, float $quantity, float $wastage): array
    {
        return [
            'feeding_date' => '2026-09-21',
            'feeding_time' => '08:30',
            'branch_id' => $setup['branch']->id,
            'farm_information_id' => $setup['farm']->id,
            'animal_balance_id' => $setup['animalBalance']->id,
            'notes' => 'Morning feed',
            'lines' => [[
                'food_item_id' => $setup['foodItem']->id,
                'inventory_id' => $setup['inventory']->id,
                'stock_lot_id' => $setup['stockLot']->id,
                'source_location' => 'Pen F1',
                'stock_uom_id' => $setup['uom']->id,
                'quantity' => $quantity,
                'wastage_quantity' => $wastage,
                'notes' => 'Normal feeding',
            ]],
        ];
    }

    private function actingWithFarmPermissions(): User
    {
        foreach (['farms.view', 'farms.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo(['farms.view', 'farms.manage']);
        Sanctum::actingAs($user);

        return $user;
    }
}
