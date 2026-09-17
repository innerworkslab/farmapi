<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Models\Equipment;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Seeder;

class DemoBusinessMasterSeeder extends Seeder
{
    public function run(): void
    {
        $yangon = Branch::query()->where('code', 'BR-YGN')->firstOrFail();
        $mandalay = Branch::query()->where('code', 'BR-MDY')->firstOrFail();

        $kg = Uom::query()->updateOrCreate(['code' => 'KG'], [
            'name' => 'Kilogram', 'symbol' => 'kg', 'category' => 'weight', 'status' => 'active',
        ]);
        $bag = Uom::query()->updateOrCreate(['code' => 'BAG'], [
            'name' => 'Bag', 'symbol' => 'bag', 'category' => 'quantity', 'status' => 'active',
        ]);
        $dose = Uom::query()->updateOrCreate(['code' => 'DOSE'], [
            'name' => 'Dose', 'symbol' => 'dose', 'category' => 'quantity', 'status' => 'active',
        ]);

        $feedSupplier = Supplier::query()->updateOrCreate(['code' => 'SUP-FEED-001'], [
            'type' => 'food',
            'name' => 'Golden Feed Supply',
            'contact_person' => 'Daw Mya',
            'phone_number' => '+95 9 500000001',
            'supplied_categories' => ['feed', 'supplement'],
            'preferred_branch_id' => $yangon->id,
            'lead_time_days' => 3,
            'minimum_order_amount' => 500000,
            'payment_terms' => 'net_15',
            'credit_limit' => 5000000,
            'opening_balance_type' => 'none',
            'opening_balance' => 0,
            'status' => 'active',
        ]);

        $medicineSupplier = Supplier::query()->updateOrCreate(['code' => 'SUP-MED-001'], [
            'type' => 'medicine',
            'name' => 'Healthy Herd Pharma',
            'contact_person' => 'U Kyaw',
            'phone_number' => '+95 9 500000002',
            'supplied_categories' => ['medicine', 'vaccine'],
            'preferred_branch_id' => $mandalay->id,
            'lead_time_days' => 5,
            'minimum_order_amount' => 300000,
            'payment_terms' => 'net_30',
            'credit_limit' => 3000000,
            'opening_balance_type' => 'none',
            'opening_balance' => 0,
            'status' => 'active',
        ]);

        Customer::query()->updateOrCreate(['code' => 'CUS-YGN-001'], [
            'type' => 'retail',
            'name' => 'Yangon Fresh Meat Shop',
            'contact_person' => 'Ko Aung',
            'phone_number' => '+95 9 600000001',
            'delivery_address' => 'North Dagon Market',
            'township' => 'North Dagon',
            'state_region' => 'Yangon',
            'preferred_branch_id' => $yangon->id,
            'price_level' => 'standard',
            'payment_terms' => 'cash',
            'credit_limit' => 0,
            'opening_balance' => 0,
            'status' => 'active',
        ]);

        Inventory::query()->updateOrCreate(['code' => 'INV-YGN-FEED'], [
            'name' => 'Yangon Feed Store',
            'type' => 'feed',
            'branch_id' => $yangon->id,
            'physical_address' => 'Feed warehouse beside Barn A',
            'building_zone' => 'Zone A',
            'rack_bin' => 'R1',
            'allowed_item_categories' => ['feed'],
            'inventory_gl_account' => '1300-FEED',
            'status' => 'active',
        ]);

        Food::query()->updateOrCreate(['code' => 'FOD-CATTLE-PELLET'], [
            'name' => 'Cattle Growth Pellet',
            'category' => 'feed',
            'type' => 'pellet',
            'target_animal_type' => 'cattle',
            'life_stage' => 'grower',
            'brand' => 'Golden Feed',
            'manufacturer' => 'Golden Feed Supply',
            'feed_form' => 'pellet',
            'purchase_uom_id' => $bag->id,
            'stock_uom_id' => $kg->id,
            'consumption_uom_id' => $kg->id,
            'uom_conversion' => 50,
            'default_supplier_id' => $feedSupplier->id,
            'purchase_price' => 75000,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'minimum_stock_level' => 500,
            'maximum_stock_level' => 5000,
            'reorder_level' => 1000,
            'reorder_quantity' => 2000,
            'inventory_account' => '1300-FEED',
            'feed_expense_account' => '5100-FEED',
            'status' => 'active',
        ]);

        Medicine::query()->updateOrCreate(['code' => 'MED-IVM-001'], [
            'name' => 'Ivermectin Injection',
            'generic_name' => 'Ivermectin',
            'type' => 'antiparasitic',
            'category' => 'injection',
            'target_animal_type' => 'cattle',
            'target_disease' => 'parasites',
            'active_ingredient' => 'Ivermectin 1%',
            'strength' => '10mg/ml',
            'dosage_form' => 'injection',
            'dosage_unit' => 'ml',
            'recommended_dosage' => 1,
            'dosage_frequency' => 'single_dose',
            'manufacturer' => 'Healthy Herd Pharma',
            'default_supplier_id' => $medicineSupplier->id,
            'registration_number' => 'REG-IVM-001',
            'package_size' => '100ml bottle',
            'purchase_uom_id' => $dose->id,
            'stock_uom_id' => $dose->id,
            'usage_uom_id' => $dose->id,
            'uom_conversion' => 1,
            'barcode_sku' => 'MED-IVM-001-SKU',
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'cold_chain_required' => false,
            'purchase_price' => 45000,
            'standard_cost' => 45000,
            'minimum_stock_level' => 20,
            'maximum_stock_level' => 200,
            'reorder_level' => 50,
            'reorder_quantity' => 100,
            'tax_type' => 'standard',
            'inventory_account' => '1310-MED',
            'medicine_expense_account' => '5200-MED',
            'status' => 'active',
        ]);

        $animal = Animal::query()->updateOrCreate(['code' => 'ANI-CATTLE-BATCH-001'], [
            'tracking_type' => 'batch',
            'batch_flock_number' => 'BATCH-CATTLE-001',
            'name' => 'Cattle Batch 001',
            'type' => 'cattle',
            'category' => 'beef',
            'breed' => 'Brahman Cross',
            'gender' => 'mixed',
            'color_marking' => 'mixed brown',
        ]);

        Equipment::query()->updateOrCreate(['code' => 'EQP-SCALE-001'], [
            'name' => 'Digital Livestock Scale',
            'category' => 'weighing',
            'brand' => 'FarmTech',
            'model' => 'FT-2000',
            'serial_number' => 'FT2000-MM-001',
            'manufacturer' => 'FarmTech',
            'supplier_id' => null,
            'purchase_cost' => 1200000,
        ]);

        FarmInformation::query()->updateOrCreate([
            'branch_id' => $yangon->id,
            'name' => 'Yangon Barn A',
            'house_barn' => 'Barn A',
            'pen_cage_pond' => 'Pen 1',
        ], [
            'current_animal_id' => $animal->id,
            'responsible_employee_id' => User::query()->where('email', 'admin@example.com')->value('id'),
        ]);
    }
}