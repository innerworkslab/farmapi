<?php

namespace Tests\Feature\Farms;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FarmNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_farm_navigation_returns_authorized_farm_summary_and_animals(): void
    {
        $user = $this->actingWithFarmPermissions();
        $setup = $this->setupFarmStock();
        $user->branches()->attach($setup['branch']->id);

        $this->getJson('/api/v1/farms?branch_id='.$setup['branch']->id.'&search=North')
            ->assertOk()
            ->assertJsonPath('data.0.id', $setup['farm']->id)
            ->assertJsonPath('data.0.name', 'North Barn')
            ->assertJsonPath('data.0.branch.id', $setup['branch']->id)
            ->assertJsonPath('data.0.animal_count', 41)
            ->assertJsonPath('data.0.active_batch_count', 1)
            ->assertJsonPath('data.0.individual_animal_count', 1)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/farms/'.$setup['farm']->id)
            ->assertOk()
            ->assertJsonPath('data.id', $setup['farm']->id)
            ->assertJsonPath('data.metrics.total_animal_count', 41)
            ->assertJsonPath('data.metrics.active_batch_count', 1)
            ->assertJsonPath('data.metrics.individual_animal_count', 1)
            ->assertJsonPath('data.animals.0.tracking_type', 'batch')
            ->assertJsonPath('data.animals.0.current_quantity', 40);

        $this->getJson('/api/v1/farms/'.$setup['farm']->id.'/animals?tracking_type=individual')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tracking_type', 'individual')
            ->assertJsonPath('data.0.rfid', 'RFID-001');
    }

    public function test_farm_navigation_respects_user_branch_scope(): void
    {
        $user = $this->actingWithFarmPermissions();
        $setup = $this->setupFarmStock();

        $otherBranch = Branch::query()->create([
            'code' => 'BR-OTHER',
            'name' => 'Other Branch',
            'status' => 'active',
        ]);
        $user->branches()->attach($otherBranch->id);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/farms/'.$setup['farm']->id)
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function setupFarmStock(): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-FARM',
            'name' => 'Farm Branch',
            'status' => 'active',
        ]);
        $uom = Uom::query()->create([
            'code' => 'HEAD',
            'name' => 'Head',
            'symbol' => 'head',
            'category' => 'count',
            'status' => 'active',
        ]);
        $inventory = Inventory::query()->create([
            'code' => 'INV-FARM',
            'name' => 'Farm Inventory',
            'type' => 'animal',
            'branch_id' => $branch->id,
            'allowed_item_categories' => ['animal'],
            'status' => 'active',
        ]);
        $batch = Animal::query()->create([
            'code' => 'ANI-BATCH',
            'tracking_type' => 'batch',
            'batch_flock_number' => 'BATCH-001',
            'name' => 'Brahman Batch 1',
            'type' => 'cattle',
            'category' => 'beef',
            'breed' => 'Brahman',
            'gender' => 'mixed',
        ]);
        $individual = Animal::query()->create([
            'code' => 'ANI-RFID',
            'tracking_type' => 'individual',
            'ear_tag_rfid_number' => 'RFID-001',
            'name' => 'RFID Cow 1',
            'type' => 'cattle',
            'category' => 'beef',
            'breed' => 'Brahman',
            'gender' => 'female',
        ]);
        $batchItem = app(ItemRegistryService::class)->syncFromMaster($batch);
        $individualItem = app(ItemRegistryService::class)->syncFromMaster($individual);
        $farm = FarmInformation::query()->create([
            'name' => 'North Barn',
            'branch_id' => $branch->id,
            'house_barn' => 'Barn N',
            'pen_cage_pond' => 'Pen 1',
            'current_animal_id' => $batch->id,
        ]);

        InventoryBalance::query()->create([
            'identity_key' => 'FARM-BATCH',
            'category' => 'animal',
            'item_type' => 'animal',
            'item_id' => $batchItem->id,
            'branch_id' => $branch->id,
            'inventory_id' => $inventory->id,
            'farm_information_id' => $farm->id,
            'location' => 'Pen 1',
            'stock_uom_id' => $uom->id,
            'on_hand_quantity' => 40,
            'available_quantity' => 40,
        ]);
        InventoryBalance::query()->create([
            'identity_key' => 'FARM-RFID',
            'category' => 'animal',
            'item_type' => 'animal',
            'item_id' => $individualItem->id,
            'branch_id' => $branch->id,
            'inventory_id' => $inventory->id,
            'farm_information_id' => $farm->id,
            'location' => 'Pen 1',
            'stock_uom_id' => $uom->id,
            'on_hand_quantity' => 1,
            'available_quantity' => 1,
        ]);

        return compact('branch', 'uom', 'inventory', 'batch', 'individual', 'farm');
    }

    private function actingWithFarmPermissions(): User
    {
        Permission::findOrCreate('farms.view', 'web');

        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo('farms.view');
        Sanctum::actingAs($user);

        return $user;
    }
}