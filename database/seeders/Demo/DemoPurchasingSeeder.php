<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Database\Seeder;

class DemoPurchasingSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $invoiceService = app(PurchaseInvoiceService::class);
        $receiptService = app(PurchaseReceiptService::class);
        $items = app(ItemRegistryService::class);

        $this->seedFeedPurchase($actor, $invoiceService, $receiptService, $items);
        $this->seedMedicinePurchase($actor, $invoiceService, $receiptService, $items);
    }

    private function seedFeedPurchase(User $actor, PurchaseInvoiceService $invoiceService, PurchaseReceiptService $receiptService, ItemRegistryService $items): void
    {
        if (StockLot::query()->where('receipt_lot_number', 'LOT-PUR-FEED-001')->exists()) {
            return;
        }

        $branch = Branch::query()->where('code', 'BR-YGN')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-FEED-001')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'INV-YGN-FEED')->firstOrFail();
        $food = Food::query()->where('code', 'FOD-CATTLE-PELLET')->firstOrFail();
        $item = $items->syncFromMaster($food);
        $bag = Uom::query()->where('code', 'BAG')->firstOrFail();
        $kg = Uom::query()->where('code', 'KG')->firstOrFail();

        $invoice = PurchaseInvoice::query()->where('invoice_number', 'PINV-DEMO-FEED-001')->first();
        if (! $invoice) {
            $invoice = $invoiceService->create([
                'invoice_number' => 'PINV-DEMO-FEED-001',
                'invoice_date' => now()->toDateString(),
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'notes' => 'Demo feed purchase for purchase-to-inventory ledger testing.',
                'lines' => [[
                    'category' => 'food',
                    'item_id' => $item->id,
                    'purchase_uom_id' => $bag->id,
                    'stock_uom_id' => $kg->id,
                    'conversion_factor' => 50,
                    'quantity' => 20,
                    'unit_price' => 75000,
                    'discount_type' => 'percentage',
                    'discount_value' => 2,
                    'foc_type' => 'quantity',
                    'foc_value' => 2,
                    'tax_rate' => 0,
                    'target_inventory_id' => $inventory->id,
                    'target_location' => 'R2',
                ]],
            ], $actor);
        }

        $receipt = $receiptService->create([
            'receipt_number' => 'PREC-DEMO-FEED-001',
            'purchase_invoice_id' => $invoice->id,
            'receipt_date' => now()->toDateString(),
            'idempotency_key' => 'demo-feed-receipt-001',
            'notes' => 'Demo confirmed feed receipt.',
            'lines' => [[
                'purchase_invoice_line_id' => $invoice->lines()->firstOrFail()->id,
                'accepted_quantity' => 20,
                'accepted_foc_quantity' => 2,
                'target_location' => 'R2',
                'supplier_batch_number' => 'SUP-PUR-FEED-B001',
                'receipt_lot_number' => 'LOT-PUR-FEED-001',
                'manufacturing_date' => now()->subWeeks(2)->toDateString(),
                'expiry_date' => now()->addMonths(11)->toDateString(),
            ]],
        ], $actor);

        $receiptService->confirm($receipt, $actor);
    }

    private function seedMedicinePurchase(User $actor, PurchaseInvoiceService $invoiceService, PurchaseReceiptService $receiptService, ItemRegistryService $items): void
    {
        if (StockLot::query()->where('receipt_lot_number', 'LOT-PUR-MED-001')->exists()) {
            return;
        }

        $branch = Branch::query()->where('code', 'BR-MDY')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-MED-001')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'INV-MDY-MED')->firstOrFail();
        $medicine = Medicine::query()->where('code', 'MED-IVM-001')->firstOrFail();
        $item = $items->syncFromMaster($medicine);
        $dose = Uom::query()->where('code', 'DOSE')->firstOrFail();

        $invoice = PurchaseInvoice::query()->where('invoice_number', 'PINV-DEMO-MED-001')->first();
        if (! $invoice) {
            $invoice = $invoiceService->create([
                'invoice_number' => 'PINV-DEMO-MED-001',
                'invoice_date' => now()->toDateString(),
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'notes' => 'Demo medicine purchase for purchase-to-inventory ledger testing.',
                'lines' => [[
                    'category' => 'medicine',
                    'item_id' => $item->id,
                    'purchase_uom_id' => $dose->id,
                    'stock_uom_id' => $dose->id,
                    'conversion_factor' => 1,
                    'quantity' => 30,
                    'unit_price' => 45000,
                    'foc_type' => 'quantity',
                    'foc_value' => 3,
                    'tax_rate' => 0,
                    'target_inventory_id' => $inventory->id,
                    'target_location' => 'MED-R2',
                ]],
            ], $actor);
        }

        $receipt = $receiptService->create([
            'receipt_number' => 'PREC-DEMO-MED-001',
            'purchase_invoice_id' => $invoice->id,
            'receipt_date' => now()->toDateString(),
            'idempotency_key' => 'demo-med-receipt-001',
            'notes' => 'Demo confirmed medicine receipt.',
            'lines' => [[
                'purchase_invoice_line_id' => $invoice->lines()->firstOrFail()->id,
                'accepted_quantity' => 30,
                'accepted_foc_quantity' => 3,
                'target_location' => 'MED-R2',
                'supplier_batch_number' => 'SUP-PUR-MED-B001',
                'receipt_lot_number' => 'LOT-PUR-MED-001',
                'manufacturing_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addMonths(18)->toDateString(),
                'cold_chain_required' => false,
                'cold_chain_status' => 'compliant',
            ]],
        ], $actor);

        $receiptService->confirm($receipt, $actor);
    }
}