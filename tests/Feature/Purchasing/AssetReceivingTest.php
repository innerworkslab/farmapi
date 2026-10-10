<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Farms\Models\FarmSetting;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AssetReceivingTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_receiving_partial_foc_and_repeat_confirmation(): void
    {
        $s = $this->fixture('batch');
        $receipt = $this->receipt($s, 3, 1, ['batch_number' => 'NEW-BATCH', 'date_of_birth' => '2026-01-01', 'weight' => 10]);
        $this->postJson('/api/v1/purchasing/receipts/'.$receipt.'/confirm')->assertOk();
        $this->postJson('/api/v1/purchasing/receipts/'.$receipt.'/confirm')->assertOk();
        $this->assertDatabaseHas('farm_animal_profiles', ['initial_quantity' => 4, 'death_quantity' => 0]);
        $this->assertDatabaseHas('inventory_balances', ['category' => 'animal', 'on_hand_quantity' => 4, 'farm_information_id' => $s['farm']->id]);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $s['invoice'], 'delivery_status' => 'partially_received']);
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
        $next = $this->receipt($s, 2, 0, []);
        $this->postJson('/api/v1/purchasing/receipts/'.$next.'/confirm')->assertOk();
        $this->assertDatabaseHas('purchase_invoices', ['id' => $s['invoice'], 'delivery_status' => 'fully_received']);
    }

    public function test_individual_receiving_unique_rfid_and_transaction_rollback(): void
    {
        $s = $this->fixture('individual');
        $r = $this->receipt($s, 2, 1, ['animals' => [['rfid' => 'NEW-1'], ['rfid' => 'NEW-2'], ['rfid' => 'NEW-3', 'foc' => true]]]);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertOk();
        $this->assertDatabaseCount('farm_animal_profiles', 3);
        $this->assertDatabaseCount('inventory_ledger_entries', 3);
        $r = $this->receipt($s, 2, 0, ['animals' => [['rfid' => 'NEW-4'], ['rfid' => 'NEW-1']]]);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertUnprocessable();
        $this->assertDatabaseMissing('animals', ['ear_tag_rfid_number' => 'NEW-4']);
        $this->assertDatabaseCount('inventory_ledger_entries', 3);
        $this->assertDatabaseHas('purchase_receipts', ['id' => $r, 'status' => 'draft']);
    }

    public function test_serial_and_quantity_equipment_receiving(): void
    {
        $s = $this->fixture('equipment');
        $r = $this->receipt($s, 2, 1, ['units' => [['serial_number' => 'SN1', 'asset_tag' => 'AS1'], ['serial_number' => 'SN2'], ['asset_tag' => 'AS3', 'foc' => true]]]);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertOk();
        $this->assertDatabaseCount('inventory_equipment_instances', 3);
        $this->assertDatabaseHas('inventory_equipment_instances', ['serial_number' => 'SN1', 'farm_information_id' => $s['farm']->id, 'lifecycle_status' => 'assigned']);
        $r = $this->receipt($s, 1, 0, ['units' => [['serial_number' => 'SN1']]]);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertUnprocessable();
        $r = $this->receipt($s, 3, 0, ['tracking_mode' => 'quantity']);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['category' => 'equipment', 'equipment_instance_id' => null, 'on_hand_quantity' => 3]);
        $this->assertDatabaseCount('inventory_equipment_instances', 3);
    }

    public function test_receiving_rejects_fractional_counts_and_inactive_farms(): void
    {
        $s = $this->fixture('batch');
        $r = $this->receipt($s, 1.5, 0, []);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertUnprocessable();
        FarmSetting::query()->create(['farm_information_id' => $s['farm']->id, 'status' => 'inactive']);
        $r = $this->receipt($s, 1, 0, []);
        $this->postJson('/api/v1/purchasing/receipts/'.$r.'/confirm')->assertUnprocessable();
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
    }

    private function receipt(array $s, float $paid, float $foc, array $metadata): int
    {
        return $this->postJson('/api/v1/purchasing/receipts', ['purchase_invoice_id' => $s['invoice'], 'receipt_date' => now()->toDateString(), 'lines' => [['purchase_invoice_line_id' => $s['line'], 'accepted_quantity' => $paid, 'accepted_foc_quantity' => $foc, 'metadata' => $metadata]]])->assertCreated()->json('data.id');
    }

    private function fixture(string $type): array
    {
        $user = $this->actingWithPurchasingPermissions();
        $branch = Branch::query()->create(['code' => 'B', 'name' => 'Branch', 'status' => 'active']);
        $user->branches()->attach($branch->id);
        $uom = Uom::query()->create(['code' => 'HEAD', 'name' => 'Count', 'symbol' => 'head', 'category' => 'unit', 'status' => 'active']);
        $supplier = Supplier::query()->create(['code' => 'SUP', 'name' => 'Supplier', 'type' => 'animal', 'phone_number' => '123', 'supplied_categories' => ['animal', 'equipment'], 'status' => 'active']);
        $inventory = Inventory::query()->create(['code' => 'INV', 'name' => 'Farm store', 'type' => 'animal', 'branch_id' => $branch->id, 'allowed_item_categories' => ['animal', 'equipment'], 'status' => 'active']);
        $farm = FarmInformation::query()->create(['name' => 'Farm', 'branch_id' => $branch->id, 'house_barn' => 'Barn', 'pen_cage_pond' => 'Pen']);
        $master = $type === 'equipment' ? Equipment::query()->create(['code' => 'EQ', 'name' => 'Tools', 'category' => 'farm']) : Animal::query()->create(['code' => 'ANI', 'tracking_type' => $type, 'batch_flock_number' => $type === 'batch' ? 'TEMPLATE-BATCH' : null, 'ear_tag_rfid_number' => $type === 'individual' ? 'TEMPLATE-RFID' : null, 'name' => 'Cattle', 'type' => 'cattle', 'category' => 'beef', 'breed' => 'Brahman', 'gender' => 'mixed']);
        $item = app(ItemRegistryService::class)->syncFromMaster($master);
        $category = $type === 'equipment' ? 'equipment' : 'animal';
        $response = $this->postJson('/api/v1/purchasing/invoices', ['invoice_date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'lines' => [['category' => $category, 'item_id' => $item->id, 'quantity' => 5, 'unit_price' => 100, 'foc_type' => 'quantity', 'foc_value' => 1, 'purchase_uom_id' => $uom->id, 'stock_uom_id' => $uom->id, 'conversion_factor' => 1, 'target_inventory_id' => $inventory->id, 'target_farm_information_id' => $farm->id, 'target_location' => 'Pen']]])->assertCreated();
        $invoice = $response->json('data.id');
        $line = $response->json('data.lines.0.id');

        return compact('branch', 'farm', 'inventory', 'invoice', 'line');
    }

    private function actingWithPurchasingPermissions(): User
    {
        $permissions = [
            'purchasing.invoices.view',
            'purchasing.invoices.create',
            'purchasing.invoices.update',
            'purchasing.invoices.cancel',
            'purchasing.receipts.view',
            'purchasing.receipts.create',
            'purchasing.receipts.confirm',
            'inventory.items.view',
            'inventory.balances.view',
            'inventory.ledger.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }
}
