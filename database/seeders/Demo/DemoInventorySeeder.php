<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\InventoryAdjustmentService;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Seeder;

class DemoInventorySeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $service = app(InventoryAdjustmentService::class);

        $yangon = Branch::query()->where('code', 'BR-YGN')->firstOrFail();
        $mandalay = Branch::query()->where('code', 'BR-MDY')->firstOrFail();
        $kg = Uom::query()->where('code', 'KG')->firstOrFail();
        $dose = Uom::query()->where('code', 'DOSE')->firstOrFail();

        $feedInventory = Inventory::query()->where('code', 'INV-YGN-FEED')->firstOrFail();
        $medicineInventory = Inventory::query()->updateOrCreate(['code' => 'INV-MDY-MED'], [
            'name' => 'Mandalay Medicine Store',
            'type' => 'medicine',
            'branch_id' => $mandalay->id,
            'physical_address' => 'Medicine store beside clinic room',
            'building_zone' => 'Clinic',
            'rack_bin' => 'MED-R1',
            'allowed_item_categories' => ['medicine', 'vaccine'],
            'inventory_gl_account' => '1310-MED',
            'status' => 'active',
        ]);

        if (! StockLot::query()->where('receipt_lot_number', 'LOT-FEED-001')->exists()) {
            $food = Food::query()->where('code', 'FOD-CATTLE-PELLET')->firstOrFail();
            $supplier = Supplier::query()->where('code', 'SUP-FEED-001')->firstOrFail();

            $adjustment = $service->create([
                'type' => 'opening_balance',
                'adjustment_date' => now()->toDateString(),
                'branch_id' => $yangon->id,
                'inventory_id' => $feedInventory->id,
                'reason_type' => 'opening_balance',
                'reason' => 'Demo opening feed balance',
                'lines' => [[
                    'category' => 'food',
                    'item_id' => $food->id,
                    'location' => 'R1',
                    'stock_uom_id' => $kg->id,
                    'adjustment_quantity' => 1000,
                    'direction' => 'in',
                    'new_identity' => true,
                    'supplier_id' => $supplier->id,
                    'supplier_batch_number' => 'SUP-FEED-B001',
                    'receipt_lot_number' => 'LOT-FEED-001',
                    'manufacturing_date' => now()->subMonth()->toDateString(),
                    'expiry_date' => now()->addMonths(10)->toDateString(),
                    'lot_status' => 'available',
                ]],
            ], $actor);

            $service->confirm($service->submit($adjustment, $actor), $actor);
        }

        if (! StockLot::query()->where('receipt_lot_number', 'LOT-MED-001')->exists()) {
            $medicine = Medicine::query()->where('code', 'MED-IVM-001')->firstOrFail();
            $supplier = Supplier::query()->where('code', 'SUP-MED-001')->firstOrFail();

            $adjustment = $service->create([
                'type' => 'opening_balance',
                'adjustment_date' => now()->toDateString(),
                'branch_id' => $mandalay->id,
                'inventory_id' => $medicineInventory->id,
                'reason_type' => 'opening_balance',
                'reason' => 'Demo opening medicine balance',
                'lines' => [[
                    'category' => 'medicine',
                    'item_id' => $medicine->id,
                    'location' => 'MED-R1',
                    'stock_uom_id' => $dose->id,
                    'adjustment_quantity' => 80,
                    'direction' => 'in',
                    'new_identity' => true,
                    'supplier_id' => $supplier->id,
                    'supplier_batch_number' => 'SUP-MED-B001',
                    'receipt_lot_number' => 'LOT-MED-001',
                    'manufacturing_date' => now()->subMonths(2)->toDateString(),
                    'expiry_date' => now()->addMonths(18)->toDateString(),
                    'lot_status' => 'available',
                ]],
            ], $actor);

            $service->confirm($service->submit($adjustment, $actor), $actor);
        }
    }
}