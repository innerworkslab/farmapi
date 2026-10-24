<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Farms\Services\FarmOperationService;
use App\Modules\Farms\Services\FarmReportService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Database\Seeders\AnimalClassificationSeeder;
use Illuminate\Database\Seeder;

class DemoFarmsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(\Database\Seeders\PurchasePaymentCategorySeeder::class);
        $this->call(AnimalClassificationSeeder::class);
        $actor = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $branch = Branch::query()->where('code', 'BR-YGN')->firstOrFail();
        $uom = Uom::query()->firstOrCreate(['code' => 'HEAD-FARM'], ['name' => 'Animal / equipment count', 'symbol' => 'unit', 'category' => 'unit', 'status' => 'active']);
        $inventory = Inventory::query()->firstOrCreate(['code' => 'INV-FARM-FINAL'], ['name' => 'Farm receiving store', 'type' => 'animal', 'branch_id' => $branch->id, 'allowed_item_categories' => ['animal', 'equipment', 'food', 'medicine'], 'status' => 'active']);
        $supplier = Supplier::query()->firstOrCreate(['code' => 'SUP-FARM-FINAL'], ['name' => 'Farm demo supplier', 'type' => 'animal', 'phone_number' => '09700000000', 'supplied_categories' => ['animal', 'equipment', 'food', 'medicine'], 'status' => 'active']);
        $farm = FarmInformation::query()->firstOrCreate(['name' => 'Farm management demo'], ['branch_id' => $branch->id, ...\Database\Seeders\FarmLocationSeeder::references($branch->id, 'Demo Barn', 'Demo Pen'), 'responsible_employee_id' => $actor->id]);
        $kg = Uom::query()->firstOrCreate(['code' => 'KG-FARM'], ['name' => 'Farm Kilogram', 'symbol' => 'kg', 'category' => 'weight', 'status' => 'active']);
        $dose = Uom::query()->firstOrCreate(['code' => 'DOSE-FARM'], ['name' => 'Farm Dose', 'symbol' => 'dose', 'category' => 'unit', 'status' => 'active']);
        $masters = [
            Animal::query()->firstOrCreate(['code' => 'ANI-FINAL-BATCH'], ['tracking_type' => 'batch', 'batch_flock_number' => 'FINAL-BATCH', 'name' => 'Demo cattle batch', ...AnimalClassificationSeeder::cattleReferences(), 'gender' => 'mixed']),
            Animal::query()->firstOrCreate(['code' => 'ANI-FINAL-RFID'], ['tracking_type' => 'individual', 'ear_tag_rfid_number' => 'FINAL-RFID', 'name' => 'Demo individual cattle', ...AnimalClassificationSeeder::cattleReferences(), 'gender' => 'female']),
            Equipment::query()->firstOrCreate(['code' => 'EQ-FINAL'], ['name' => 'Demo farm tools', 'category' => 'farm']),
            Food::query()->firstOrCreate(['code' => 'FOD-FARM-FINAL'], ['name' => 'Farm demo feed', 'category' => 'feed', 'target_animal_type' => 'cattle', 'purchase_uom_id' => $kg->id, 'stock_uom_id' => $kg->id, 'consumption_uom_id' => $kg->id, 'uom_conversion' => 1, 'purchase_price' => 2, 'default_supplier_id' => $supplier->id, 'batch_tracking' => true, 'expiry_tracking' => true, 'status' => 'active']),
            Medicine::query()->firstOrCreate(['code' => 'MED-FARM-FINAL'], ['name' => 'Farm demo medicine', 'type' => 'medicine', 'category' => 'antibiotic', 'purchase_uom_id' => $dose->id, 'stock_uom_id' => $dose->id, 'usage_uom_id' => $dose->id, 'uom_conversion' => 1, 'purchase_price' => 10, 'default_supplier_id' => $supplier->id, 'batch_tracking' => true, 'expiry_tracking' => true, 'status' => 'active']),
        ];
        foreach ($masters as $index => $master) {
            $item = app(ItemRegistryService::class)->syncFromMaster($master);
            $quantity = in_array($index, [0, 3, 4]) ? 100 : 1;
            $stockUom = $index === 3 ? $kg : ($index === 4 ? $dose : $uom);
            $invoice = PurchaseInvoice::query()->where('invoice_number', 'PINV-FARM-FINAL-'.$index)->first();
            if (! $invoice) {
                $invoice = app(PurchaseInvoiceService::class)->create(['invoice_number' => 'PINV-FARM-FINAL-'.$index, 'invoice_date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'cashbook_id' => DemoPurchasingSeeder::cashbookForBranch($branch->id)->id, 'paid_amount' => 0,
                    'lines' => [['category' => $item->category, 'item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => 100, 'purchase_uom_id' => $stockUom->id, 'stock_uom_id' => $stockUom->id, 'conversion_factor' => 1, 'target_farm_information_id' => $farm->id]]], $actor);
            }
            $receipt = PurchaseReceipt::query()->where('receipt_number', 'PREC-FARM-FINAL-'.$index)->first();
            if (! $receipt) {
                $receipt = app(PurchaseReceiptService::class)->create(['receipt_number' => 'PREC-FARM-FINAL-'.$index, 'purchase_invoice_id' => $invoice->id, 'receipt_date' => now()->toDateString(),
                    'lines' => [['purchase_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'target_inventory_id' => $inventory->id, 'target_location' => 'Demo Pen', 'accepted_quantity' => $quantity, 'receipt_lot_number' => $index >= 3 ? 'LOT-FARM-FINAL-'.$index : null, 'cold_chain_required' => false, 'cold_chain_status' => 'compliant', 'manufacturing_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(), 'metadata' => $index === 2 ? ['units' => [['serial_number' => 'FINAL-EQUIPMENT-001', 'asset_tag' => 'FINAL-ASSET-001']]] : []]]], $actor);
            }
            if ($receipt->status === 'draft') {
                app(PurchaseReceiptService::class)->confirm($receipt, $actor);
            }
        }
        $balance = InventoryBalance::query()->where('farm_information_id', $farm->id)->where('category', 'animal')->where('on_hand_quantity', '>', 1)->firstOrFail();
        app(FarmReportService::class)->saveSettings($farm, ['timezone' => 'Asia/Yangon', 'daily_mortality_threshold' => 2, 'cumulative_mortality_threshold' => 5], $actor);
        foreach ([['health', 5, ['reason' => 'Demo observation', 'health_status' => 'under_observation']], ['mortality', 1, ['reason' => 'Demo mortality', 'disposal_method' => 'Approved burial']]] as [$type, $quantity, $payload]) {
            if (FarmOperation::query()->where('idempotency_key', 'demo-farm-final-'.$type)->exists()) {
                continue;
            }
            $service = app(FarmOperationService::class);
            $record = $service->create(['type' => $type, 'branch_id' => $branch->id, 'farm_information_id' => $farm->id, 'activity_date' => now()->toDateString(), 'idempotency_key' => 'demo-farm-final-'.$type, 'targets' => [['animal_balance_id' => $balance->id, 'quantity' => $quantity]], 'payload' => $payload], $actor);
            $service->transition($service->transition($record, 'submit', $actor), 'confirm', $actor);
        }
    }
}
