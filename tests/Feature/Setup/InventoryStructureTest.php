<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_lifecycle_without_removed_columns(): void
    {
        [$payload, $branch] = $this->fixture();
        $this->assertFalse(Schema::hasColumn('inventories', 'building_zone'));
        $this->assertFalse(Schema::hasColumn('inventories', 'rack_bin'));
        $id = $this->postJson('/api/v1/setup/inventories', $payload)->assertCreated()
            ->assertJsonMissingPath('data.building_zone')->assertJsonMissingPath('data.rack_bin')
            ->assertJsonPath('data.branch.id', $branch->id)->json('data.id');
        $this->getJson('/api/v1/setup/inventories/'.$id)->assertOk()
            ->assertJsonMissingPath('data.building_zone')->assertJsonMissingPath('data.rack_bin');
        $payload['physical_address'] = 'New warehouse address';
        $this->postJson('/api/v1/setup/inventories/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.physical_address', 'New warehouse address');
        $this->getJson('/api/v1/setup/inventories?search=New%20warehouse&branch_id='.$branch->id.'&type=general&status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.building_zone')->assertJsonMissingPath('data.0.rack_bin');
        $this->postJson('/api/v1/setup/inventories/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->postJson('/api/v1/setup/inventories/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/setup/inventories/location-options')->assertNotFound();
        $this->deleteJson('/api/v1/setup/inventories/'.$id)->assertOk()->assertJsonPath('message', 'Inventory deleted successfully.');
        $this->assertSoftDeleted('inventories', ['id' => $id]);
        $this->getJson('/api/v1/setup/inventories/'.$id)->assertNotFound();
    }

    public function test_removed_fields_are_rejected_on_create_update_and_list(): void
    {
        [$payload] = $this->fixture();
        $removed = ['building_zone' => 'Zone A', 'rack_bin' => 'R1'];
        $this->postJson('/api/v1/setup/inventories', $payload + $removed)->assertUnprocessable()
            ->assertJsonValidationErrors(['building_zone', 'rack_bin']);
        $id = $this->postJson('/api/v1/setup/inventories', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/setup/inventories/'.$id, $payload + $removed)->assertUnprocessable()
            ->assertJsonValidationErrors(['building_zone', 'rack_bin']);
        $this->getJson('/api/v1/setup/inventories?building_zone=Zone%20A&rack_bin=R1')->assertUnprocessable()
            ->assertJsonValidationErrors(['building_zone', 'rack_bin']);
        $this->postJson('/api/v1/setup/inventories', array_replace($payload, ['code' => 'INV-BAD', 'type' => 'unknown']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/v1/setup/inventories?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_branch_scope_and_permissions_still_protect_inventory_crud(): void
    {
        [$payload, $branch] = $this->fixture();
        $own = $this->postJson('/api/v1/setup/inventories', $payload)->assertCreated()->json('data.id');
        $foreign = Branch::create(['code' => 'OTHER', 'name' => 'Other branch', 'status' => 'active']);
        $other = Inventory::create(array_replace($payload, ['code' => 'OTHER', 'branch_id' => $foreign->id]));
        $this->getJson('/api/v1/setup/inventories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->getJson('/api/v1/setup/inventories/'.$other->id)->assertForbidden();
        $this->postJson('/api/v1/setup/inventories/'.$own, array_replace($payload, ['branch_id' => $foreign->id]))->assertForbidden();
        $this->deleteJson('/api/v1/setup/inventories/'.$other->id)->assertForbidden();
        $this->postJson('/api/v1/setup/inventories/'.$other->id.'/toggle-status')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['account_status' => 'active']));
        $this->getJson('/api/v1/setup/inventories')->assertForbidden();
        $this->postJson('/api/v1/setup/inventories', $payload)->assertForbidden();
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['account_status' => 'active']);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $name = 'setup.inventories.'.$action;
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }
        $branch = Branch::create(['code' => 'INV-BR', 'name' => 'Inventory branch', 'status' => 'active']);
        $user->branches()->attach($branch->id);
        Sanctum::actingAs($user);

        return [[
            'code' => 'INV-STRUCTURE', 'name' => 'Inventory structure test', 'type' => 'general',
            'branch_id' => $branch->id, 'physical_address' => 'Warehouse address',
            'allowed_item_categories' => ['food', 'medicine'], 'inventory_gl_account' => '1300',
        ], $branch];
    }
}
