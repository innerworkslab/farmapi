<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\ItemUomService;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ItemUomContractTest extends TestCase
{
    use RefreshDatabase;

    public static function itemKinds(): array
    {
        return [['foods', 'consumption_uom_id'], ['medicines', 'usage_uom_id']];
    }

    #[DataProvider('itemKinds')]
    public function test_create_accepts_only_purchase_or_usage_as_stock_and_reports_both_factors(string $kind, string $usageField): void
    {
        [$payload, $purchase, $usage, $third] = $this->fixture($kind, $usageField);
        $url = '/api/v1/setup/'.$kind;
        $this->postJson($url, array_replace($payload, ['stock_uom_id' => $third->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_uom_id');
        $this->postJson($url, array_replace($payload, [$usageField => $purchase->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('uom_conversion');
        foreach (['0', '-1', '0.0000001', '1000000000'] as $factor) {
            $this->postJson($url, array_replace($payload, ['uom_conversion' => $factor]))
                ->assertUnprocessable()->assertJsonValidationErrors('uom_conversion');
        }
        $usage->update(['status' => 'inactive']);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($usageField);
        $usage->update(['status' => 'active']);

        $id = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.unit_roles.purchase_to_stock_factor', '1')
            ->assertJsonPath('data.unit_roles.purchase_to_usage_factor', '1000.000000')->json('data.id');
        $this->getJson($url.'/'.$id)->assertOk()
            ->assertJsonPath('data.unit_roles.purchase_unit.id', $purchase->id)
            ->assertJsonPath('data.unit_roles.usage_unit.id', $usage->id)
            ->assertJsonPath('data.unit_roles.stock_unit.id', $purchase->id);

        $payload['code'] .= '-USAGE-STOCK';
        $payload['stock_uom_id'] = $usage->id;
        $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.unit_roles.purchase_to_stock_factor', '1000.000000');
    }

    #[DataProvider('itemKinds')]
    public function test_updates_cannot_change_a_referenced_unit_definition_but_can_edit_other_details(string $kind, string $usageField): void
    {
        [$payload, $purchase, $usage, $third] = $this->fixture($kind, $usageField);
        $url = '/api/v1/setup/'.$kind;
        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $url .= '/'.$id;
        $this->postJson($url, array_replace($payload, ['stock_uom_id' => $third->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_uom_id');
        // Changing a definition before it is used is safe.
        $this->postJson($url, array_replace($payload, ['uom_conversion' => 500]))->assertOk();
        $class = $kind === 'foods' ? Food::class : Medicine::class;
        $master = $class::findOrFail($id);
        $branch = Branch::create(['code' => 'UOM-BR', 'name' => 'Unit test branch', 'status' => 'active']);
        $inventory = Inventory::create(['code' => 'UOM-INV', 'name' => 'Unit test store', 'type' => 'feed', 'branch_id' => $branch->id, 'allowed_item_categories' => ['food', 'medicine'], 'status' => 'active']);
        $balance = InventoryBalance::create(['identity_key' => 'UOM-STOCK', 'category' => $master->item->category, 'item_type' => $master->item->category, 'item_id' => $master->item->id, 'branch_id' => $branch->id, 'inventory_id' => $inventory->id, 'stock_uom_id' => $purchase->id, 'on_hand_quantity' => 2, 'available_quantity' => 2]);
        $payload['uom_conversion'] = 500;
        foreach ([['uom_conversion' => 1000], ['stock_uom_id' => $usage->id], [$usageField => $third->id], ['purchase_uom_id' => $third->id, 'stock_uom_id' => $third->id]] as $change) {
            $this->postJson($url, array_replace($payload, $change))->assertUnprocessable();
        }
        $this->postJson($url, array_replace($payload, ['name' => 'Renamed item']))->assertOk();
        $this->assertEquals(2, $balance->fresh()->on_hand_quantity);
        $balance->update(['on_hand_quantity' => 0, 'available_quantity' => 0]);
        $this->postJson($url, array_replace($payload, ['uom_conversion' => 1000]))->assertUnprocessable();
    }

    #[DataProvider('itemKinds')]
    public function test_conversion_and_total_cost_do_not_round_unit_cost_to_cents(string $kind, string $usageField): void
    {
        [$payload, $purchase, $usage] = $this->fixture($kind, $usageField);
        $id = $this->postJson('/api/v1/setup/'.$kind, array_replace($payload, ['purchase_price' => '0.33']))->assertCreated()->json('data.id');
        $class = $kind === 'foods' ? Food::class : Medicine::class;
        $master = $class::findOrFail($id);
        $units = app(ItemUomService::class);
        $this->assertSame('0.050000', $units->toStock($master, 50, $usage->id));
        $this->assertSame('0.02', $units->consumptionCost($master, '0.05'));
        $master->stock_uom_id = $usage->id;
        $this->assertSame('2000.000000', $units->toStock($master, 2, $purchase->id));
        $this->assertSame('0.02', $units->consumptionCost($master, 50));
    }

    private function fixture(string $kind, string $usageField): array
    {
        $user = User::factory()->create(['account_status' => 'active']);
        foreach (['create', 'update', 'view'] as $action) {
            $permission = 'setup.'.$kind.'.'.$action;
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
        $units = [];
        foreach (['KG', 'G', 'LB'] as $code) {
            $units[] = Uom::create(['code' => $code, 'name' => $code, 'symbol' => strtolower($code), 'category' => 'weight', 'status' => 'active']);
        }
        [$purchase, $usage, $third] = $units;
        $payload = ['code' => 'UOM-ITEM', 'name' => 'Unit test item', 'category' => 'feed', 'type' => 'medicine', 'target_animal_type' => 'cattle', 'purchase_uom_id' => $purchase->id, $usageField => $usage->id, 'stock_uom_id' => $purchase->id, 'uom_conversion' => 1000, 'purchase_price' => '10.00', 'batch_tracking' => false, 'expiry_tracking' => false, 'cold_chain_required' => false];

        return [$payload, $purchase, $usage, $third];
    }
}
