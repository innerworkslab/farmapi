<?php

namespace Tests\Feature\Farms;

use App\Models\User;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Inventory\Services\StockIdentityService;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use App\Modules\Setup\Services\AnimalClassificationService;
use Database\Seeders\Demo\DemoAuthSeeder;
use Database\Seeders\Demo\DemoFarmsSeeder;
use Database\Seeders\Demo\DemoSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FarmManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_renames_do_not_rewrite_posted_operation_snapshots(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $id = $this->operation($s, 'health', 1, ['reason' => 'Snapshot verification', 'health_status' => 'healthy']);
        $this->confirmOperation($id);
        $target = FarmOperation::findOrFail($id)->targets()->firstOrFail();
        $snapshot = $target->snapshot;
        $this->assertSame('Brahman', $snapshot['animal']['animal_breed']['name']);
        $master = $s['animalBalance']->item->itemable;
        app(AnimalClassificationService::class)->save('animal-breeds', ['name' => 'Renamed Brahman', 'animal_type_id' => $master->animal_type_id], $master->animal_breed_id);
        $this->assertSame('Renamed Brahman', $master->fresh()->animalBreed->name);
        $this->assertSame($snapshot, $target->fresh()->snapshot);
    }

    public function test_health_mortality_reports_alerts_and_reversal_are_consistent(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $s['farm']->update(['responsible_employee_id' => $user->id]);
        $this->postJson('/api/v1/farms/'.$s['farm']->id.'/settings', ['daily_mortality_threshold' => 1, 'cumulative_mortality_threshold' => 1])->assertOk();
        $health = $this->operation($s, 'health', 5, ['reason' => 'Coughing', 'health_status' => 'sick']);
        $this->confirmOperation($health);
        $this->assertEquals(40, $s['animalBalance']->fresh()->on_hand_quantity);
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/summary')->assertOk()->assertJsonPath('data.sick_animal_count', 5);
        $death = $this->operation($s, 'mortality', 2, ['reason' => 'Disease', 'disposal_method' => 'Approved burial']);
        $this->confirmOperation($death);
        $this->postJson('/api/v1/farms/operations/'.$death.'/confirm')->assertOk();
        $this->assertEquals(38, $s['animalBalance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/mortality')->assertOk()->assertJsonPath('data.0.daily_mortality_percentage', 5)->assertJsonPath('data.0.threshold_exceeded', true);
        $this->getJson('/api/v1/farms/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/animals/'.$s['animalBalance']->id)->assertOk();
        $this->postJson('/api/v1/farms/operations/'.$health.'/reverse', ['reason' => 'Too early'])->assertUnprocessable();
        $this->postJson('/api/v1/farms/operations/'.$death.'/reverse', ['reason' => 'Count corrected'])->assertCreated();
        $this->assertEquals(40, $s['animalBalance']->fresh()->on_hand_quantity);
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/summary')->assertOk()->assertJsonPath('data.death_count', 0);
        $this->postJson('/api/v1/farms/operations/'.$health.'/reverse', ['reason' => 'Original observation corrected'])->assertCreated();
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/timeline')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_dead_animals_remain_visible_but_cannot_be_fed_or_treated(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $id = $this->operation($s, 'mortality', 40, ['reason' => 'Loss', 'disposal_method' => 'Incineration']);
        $this->confirmOperation($id);
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/animals/'.$s['animalBalance']->id)->assertOk();
        $this->postJson('/api/v1/farms/feedings', $this->feedingPayload($s, 1, 0))->assertNotFound();
        $payload = $this->operationPayload($s, 'health', 1, ['reason' => 'Invalid', 'health_status' => 'sick']);
        $this->postJson('/api/v1/farms/operations', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
    }

    public function test_operations_recheck_permissions_branch_and_inactive_farm(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $p = $this->operationPayload($s, 'health', 2, ['reason' => 'Check', 'health_status' => 'sick']);
        $id = $this->postJson('/api/v1/farms/operations', $p)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/farms/operations', $p)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/farms/'.$s['farm']->id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->postJson('/api/v1/farms/operations/'.$id.'/submit')->assertUnprocessable();
        $this->postJson('/api/v1/farms/'.$s['farm']->id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/farms/operations/'.$id.'/submit')->assertOk();
        $user->branches()->detach();
        $this->getJson('/api/v1/farms/operations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/farms/operations/'.$id)->assertNotFound();
        $this->postJson('/api/v1/farms/operations/'.$id.'/confirm')->assertNotFound();
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/summary')->assertForbidden();
        $user->branches()->attach($s['branch']->id);
        $user->revokePermissionTo('farms.manage');
        $this->postJson('/api/v1/farms/operations/'.$id.'/confirm')->assertForbidden();
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
    }

    public static function medicineStockChoices(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('medicineStockChoices')]
    public function test_medication_converts_usage_dosage_and_preserves_fractional_unit_cost(bool $stockInUsage): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $other = Uom::create(['code' => 'MED-OTHER', 'name' => 'Medicine other unit', 'symbol' => 'other', 'category' => 'volume', 'status' => 'active']);
        $third = Uom::create(['code' => 'MED-THIRD', 'name' => 'Unrelated unit', 'symbol' => 'third', 'category' => 'volume', 'status' => 'active']);
        $purchaseId = $stockInUsage ? $other->id : $s['uom']->id;
        $usageId = $stockInUsage ? $s['uom']->id : $other->id;
        $medicine = Medicine::create(['code' => 'MED-CONVERT', 'name' => 'Converted medicine', 'type' => 'medicine', 'category' => 'antibiotic', 'purchase_uom_id' => $purchaseId, 'stock_uom_id' => $s['uom']->id, 'usage_uom_id' => $usageId, 'uom_conversion' => 1000, 'batch_tracking' => false, 'purchase_price' => '0.33', 'status' => 'active']);
        $item = app(ItemRegistryService::class)->syncFromMaster($medicine);
        $initial = $stockInUsage ? 2000 : 2;
        $quantity = $stockInUsage ? 50 : 0.05;
        $balance = InventoryBalance::create(['identity_key' => 'MED-CONVERT-STOCK', 'category' => 'medicine', 'item_type' => 'medicine', 'item_id' => $item->id, 'branch_id' => $s['branch']->id, 'inventory_id' => $s['inventory']->id, 'location' => 'MED', 'stock_uom_id' => $s['uom']->id, 'on_hand_quantity' => $initial, 'available_quantity' => $initial]);
        $line = ['medicine_item_id' => $item->id, 'inventory_id' => $s['inventory']->id, 'source_location' => 'MED', 'stock_uom_id' => $s['uom']->id, 'dosage' => 25, 'dosage_uom_id' => $usageId, 'quantity' => $quantity];
        $bad = $this->operationPayload($s, 'medication', 2, ['reason' => 'Invalid dosage unit', 'administration_route' => 'oral', 'lines' => [array_replace($line, ['dosage_uom_id' => $third->id])]]);
        $this->postJson('/api/v1/farms/operations', $bad)->assertUnprocessable()->assertJsonValidationErrors('dosage_uom_id');
        $id = $this->operation($s, 'medication', 2, ['reason' => 'Usage dosage conversion', 'administration_route' => 'oral', 'lines' => [$line]]);
        $this->confirmOperation($id);
        $this->getJson('/api/v1/farms/operations/'.$id)->assertOk()
            ->assertJsonPath('data.payload.posted_lines.0.required_quantity', $quantity)
            ->assertJsonPath('data.payload.posted_lines.0.variance', 0)
            ->assertJsonPath('data.payload.posted_lines.0.cost', '0.02');
        $this->assertEquals($initial - $quantity, $balance->fresh()->on_hand_quantity);
        $this->postJson('/api/v1/farms/operations/'.$id.'/reverse', ['reason' => 'Correct medicine use'])->assertCreated();
        $this->assertEquals($initial, $balance->fresh()->on_hand_quantity);
    }

    public function test_medication_posts_only_on_confirmation_and_reverses_with_audit(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $medicine = Medicine::query()->create(['code' => 'MED-TEST', 'name' => 'Treatment', 'type' => 'medicine', 'category' => 'antibiotic', 'purchase_uom_id' => $s['uom']->id, 'stock_uom_id' => $s['uom']->id, 'usage_uom_id' => $s['uom']->id, 'uom_conversion' => 1, 'batch_tracking' => false, 'purchase_price' => 10, 'status' => 'active']);
        $item = app(ItemRegistryService::class)->syncFromMaster($medicine);
        $stock = InventoryBalance::query()->create(['identity_key' => 'MED-STOCK', 'category' => 'medicine', 'item_type' => 'medicine', 'item_id' => $item->id, 'branch_id' => $s['branch']->id, 'inventory_id' => $s['inventory']->id, 'location' => 'STORE', 'stock_uom_id' => $s['uom']->id, 'on_hand_quantity' => 10, 'available_quantity' => 10]);
        $line = ['medicine_item_id' => $item->id, 'inventory_id' => $s['inventory']->id, 'source_location' => 'STORE', 'stock_uom_id' => $s['uom']->id, 'dosage' => 1, 'dosage_uom_id' => $s['uom']->id, 'quantity' => 3];
        $p = $this->operationPayload($s, 'medication', 2, ['reason' => 'Treatment', 'administration_route' => 'oral', 'withdrawal_days' => 7, 'follow_up_date' => now()->addDay()->toDateString(), 'lines' => [$line]]);
        $id = $this->postJson('/api/v1/farms/operations', $p)->assertCreated()->json('data.id');
        $this->assertEquals(10, $stock->fresh()->on_hand_quantity);
        $this->confirmOperation($id);
        $this->assertEquals(7, $stock->fresh()->on_hand_quantity);
        $this->getJson('/api/v1/farms/operations/'.$id)->assertOk()->assertJsonPath('data.payload.posted_lines.0.required_quantity', 2)->assertJsonPath('data.payload.posted_lines.0.cost', '30.00');
        $this->postJson('/api/v1/farms/operations', $p)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/farms/operations/'.$id.'/reverse', ['reason' => 'Correct dose'])->assertCreated();
        $this->assertEquals(10, $stock->fresh()->on_hand_quantity);
        $p['idempotency_key'] = 'combined';
        $line['quantity'] = 6;
        $p['payload']['lines'] = [$line, $line];
        $this->postJson('/api/v1/farms/operations', $p)->assertUnprocessable();
        $this->assertDatabaseCount('farm_operations', 2);
    }

    public function test_feeding_plans_variance_alerts_and_correction_reconcile(): void
    {
        // Keep UTC entry dates and the farm's Myanmar business day aligned at midnight boundaries.
        $this->travelTo(now()->startOfDay()->addHours(9));
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $this->postJson('/api/v1/farms/'.$s['farm']->id.'/settings', ['feed_variance_threshold' => 5, 'feed_wastage_threshold' => 5])->assertOk();
        $this->postJson('/api/v1/farms/'.$s['farm']->id.'/feeding-plans', ['plan_date' => now()->toDateString(), 'animal_balance_id' => $s['animalBalance']->id, 'food_item_id' => $s['foodItem']->id, 'stock_uom_id' => $s['uom']->id, 'quantity' => 10])->assertOk();
        $p = $this->feedingPayload($s, 12, 1);
        $p['feeding_date'] = now()->toDateString();
        $id = $this->postJson('/api/v1/farms/feedings', $p)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertOk();
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertOk();
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/summary')->assertOk()->assertJsonPath('data.feed_consumption_summary.planned_actual_by_animal.0.variance', 2)->assertJsonPath('data.feed_consumption_summary.today_cost', '12.00');
        $alerts = $this->getJson('/api/v1/farms/'.$s['farm']->id.'/alerts')->assertOk()->json('data');
        $this->assertContains('high_feed_variance', array_column($alerts, 'type'));
        $this->assertContains('high_feed_wastage', array_column($alerts, 'type'));
        $this->postJson('/api/v1/farms/feedings/'.$id.'/reverse', ['reason' => 'Wrong quantity'])->assertOk();
        $this->assertEquals(100, $s['foodBalance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_ledger_entries', 2);
        $this->getJson('/api/v1/farms/'.$s['farm']->id.'/summary')->assertOk()->assertJsonPath('data.feed_consumption_summary.today_quantity', 0);
        $this->postJson('/api/v1/farms/feedings/'.$id.'/reverse', ['reason' => 'Duplicate'])->assertUnprocessable();
    }

    public function test_group_feeding_is_linked_to_each_individual_animal(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $ids = [];
        foreach (['A', 'B'] as $tag) {
            $animal = Animal::factory()->create(['code' => 'IND-'.$tag, 'tracking_type' => 'individual', 'ear_tag_rfid_number' => 'TAG-'.$tag, 'name' => $tag, 'gender' => 'female']);
            $item = app(ItemRegistryService::class)->syncFromMaster($animal);
            $balance = InventoryBalance::query()->create(['identity_key' => 'IND-'.$tag, 'category' => 'animal', 'item_type' => 'animal', 'item_id' => $item->id, 'branch_id' => $s['branch']->id, 'inventory_id' => $s['inventory']->id, 'farm_information_id' => $s['farm']->id, 'location' => 'Pen F1', 'stock_uom_id' => $s['uom']->id, 'on_hand_quantity' => 1, 'available_quantity' => 1]);
            $ids[] = $balance->id;
        }
        $p = $this->feedingPayload($s, 2, 0);
        unset($p['animal_balance_id']);
        $p['animal_balance_ids'] = $ids;
        $id = $this->postJson('/api/v1/farms/feedings', $p)->assertCreated()->assertJsonPath('data.target.animal_count', 2)->assertJsonPath('data.lines.0.quantity_per_animal', 1)->json('data.id');
        $this->postJson('/api/v1/farms/feedings/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/farms/feedings/'.$id.'/confirm')->assertOk();
        foreach ($ids as $animalId) {
            $this->getJson('/api/v1/farms/feedings?animal_balance_id='.$animalId)->assertOk()->assertJsonCount(1, 'data');
            $this->getJson('/api/v1/farms/'.$s['farm']->id.'/animals/'.$animalId)->assertOk()->assertJsonPath('data.history_summary.feeding_records', 1);
        }
        $this->assertEquals(98, $s['foodBalance']->fresh()->on_hand_quantity);
    }

    public function test_expired_recalled_and_breached_medicine_are_blocked_at_confirmation(): void
    {
        $user = $this->actingWithFarmPermissions();
        $s = $this->setupFeedingStock(100);
        $user->branches()->attach($s['branch']->id);
        $medicine = Medicine::query()->create(['code' => 'MED-LOT', 'name' => 'Vaccine', 'type' => 'vaccine', 'category' => 'vaccine', 'stock_uom_id' => $s['uom']->id, 'purchase_uom_id' => $s['uom']->id, 'usage_uom_id' => $s['uom']->id, 'batch_tracking' => true, 'expiry_tracking' => true, 'cold_chain_required' => true, 'status' => 'active']);
        $item = app(ItemRegistryService::class)->syncFromMaster($medicine);
        $lot = StockLot::query()->create(['category' => 'medicine', 'item_type' => 'medicine', 'item_id' => $item->id, 'branch_id' => $s['branch']->id, 'inventory_id' => $s['inventory']->id, 'location' => 'MED', 'stock_uom_id' => $s['uom']->id, 'receipt_lot_number' => 'MED-LOT', 'expiry_date' => '2099-01-01', 'lot_status' => 'available', 'cold_chain_required' => true, 'cold_chain_status' => 'compliant']);
        $stock = InventoryBalance::query()->create(['identity_key' => 'MED-LOT-STOCK', 'category' => 'medicine', 'item_type' => 'medicine', 'item_id' => $item->id, 'branch_id' => $s['branch']->id, 'inventory_id' => $s['inventory']->id, 'location' => 'MED', 'stock_uom_id' => $s['uom']->id, 'stock_lot_id' => $lot->id, 'on_hand_quantity' => 10, 'available_quantity' => 10]);
        $id = $this->operation($s, 'medication', 1, ['reason' => 'Vaccination', 'administration_route' => 'injection', 'lines' => [['medicine_item_id' => $item->id, 'inventory_id' => $s['inventory']->id, 'stock_lot_id' => $lot->id, 'source_location' => 'MED', 'stock_uom_id' => $s['uom']->id, 'dosage' => 1, 'dosage_uom_id' => $s['uom']->id, 'quantity' => 1]]]);
        $this->postJson('/api/v1/farms/operations/'.$id.'/submit')->assertOk();
        foreach ([['expiry_date' => '2000-01-01'], ['lot_status' => 'recalled'], ['cold_chain_status' => 'breached']] as $bad) {
            $lot->update(array_merge(['expiry_date' => '2099-01-01', 'lot_status' => 'available', 'cold_chain_status' => 'compliant'], $bad));
            $this->postJson('/api/v1/farms/operations/'.$id.'/confirm')->assertUnprocessable();
            $this->assertEquals(10, $stock->fresh()->on_hand_quantity);
            $this->assertDatabaseCount('inventory_ledger_entries', 0);
        }
    }

    public function test_farm_demo_seeder_is_repeatable(): void
    {
        $this->seed([DemoAuthSeeder::class, DemoSetupSeeder::class, DemoFarmsSeeder::class]);
        $counts = [PurchaseReceipt::query()->count(), InventoryLedgerEntry::query()->count(), FarmOperation::query()->count()];
        $this->seed(DemoFarmsSeeder::class);
        $this->assertSame($counts, [PurchaseReceipt::query()->count(), InventoryLedgerEntry::query()->count(), FarmOperation::query()->count()]);
    }

    private function operationPayload(array $s, string $type, int $quantity, array $payload): array
    {
        return ['type' => $type, 'branch_id' => $s['branch']->id, 'farm_information_id' => $s['farm']->id, 'activity_date' => now()->toDateString(), 'idempotency_key' => fake()->uuid(), 'targets' => [['animal_balance_id' => $s['animalBalance']->id, 'quantity' => $quantity]], 'payload' => $payload];
    }

    private function operation(array $s, string $type, int $quantity, array $payload): int
    {
        return $this->postJson('/api/v1/farms/operations', $this->operationPayload($s, $type, $quantity, $payload))->assertCreated()->json('data.id');
    }

    private function confirmOperation(int $id): void
    {
        $this->postJson('/api/v1/farms/operations/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/farms/operations/'.$id.'/confirm')->assertOk();
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
            ...\Database\Seeders\FarmLocationSeeder::references($branch->id, 'Barn F', 'Pen F1'),
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
