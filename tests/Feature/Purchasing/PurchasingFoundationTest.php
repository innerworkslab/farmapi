<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchasingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_invoice_save_does_not_post_inventory_until_receipt_confirmation(): void
    {
        $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');

        $invoice = $this->createPurchaseInvoice($setup, [
            'invoice_number' => 'PINV-FOOD-001',
            'quantity' => 100,
            'unit_price' => 2.5,
            'foc_type' => 'quantity',
            'foc_value' => 10,
        ])->assertCreated();

        $invoiceId = $invoice->json('data.id');
        $invoiceLineId = $invoice->json('data.lines.0.id');

        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'delivery_status' => PurchaseInvoice::DELIVERY_NOT_RECEIVED,
            'total_expected_quantity' => 110,
            'total_received_quantity' => 0,
        ]);

        $receipt = $this->postJson('/api/v1/purchasing/receipts', [
            'receipt_number' => 'PREC-FOOD-001',
            'purchase_invoice_id' => $invoiceId,
            'receipt_date' => '2026-09-18',
            'idempotency_key' => 'receipt-food-001',
            'lines' => [[
                'purchase_invoice_line_id' => $invoiceLineId,
                'accepted_quantity' => 100,
                'accepted_foc_quantity' => 10,
                'target_location' => 'RACK-A',
                'supplier_batch_number' => 'SUP-F-001',
                'receipt_lot_number' => 'LOT-F-001',
                'manufacturing_date' => '2026-09-01',
                'expiry_date' => '2027-09-01',
            ]],
        ])->assertCreated();

        $receiptId = $receipt->json('data.id');
        $this->assertDatabaseCount('inventory_balances', 0);

        $this->postJson("/api/v1/purchasing/receipts/{$receiptId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'delivery_status' => PurchaseInvoice::DELIVERY_FULLY_RECEIVED,
            'total_received_quantity' => 110,
        ]);
        $this->assertDatabaseHas('purchase_invoice_lines', [
            'id' => $invoiceLineId,
            'received_quantity' => 100,
            'received_foc_quantity' => 10,
        ]);
        $this->assertDatabaseHas('inventory_stock_lots', [
            'category' => 'food',
            'item_id' => $setup['item']->id,
            'inventory_id' => $setup['inventory']->id,
            'receipt_lot_number' => 'LOT-F-001',
            'lot_status' => 'available',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'category' => 'food',
            'item_id' => $setup['item']->id,
            'inventory_id' => $setup['inventory']->id,
            'on_hand_quantity' => 110,
            'available_quantity' => 110,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'source_module' => 'purchasing',
            'source_type' => 'purchase_receipt',
            'transaction_type' => 'purchase_receipt',
            'direction' => 'in',
            'quantity_in' => 110,
            'balance_before' => 0,
            'balance_after' => 110,
        ]);

        $this->getJson('/api/v1/inventory/balances?category=food')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 110);
        $this->getJson('/api/v1/inventory/ledger?source_module=purchasing')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_in', 110);
    }

    public function test_receipt_confirmation_blocks_over_receipt(): void
    {
        $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        $invoice = $this->createPurchaseInvoice($setup, [
            'invoice_number' => 'PINV-OVER-001',
            'quantity' => 25,
            'foc_type' => 'quantity',
            'foc_value' => 0,
        ])->assertCreated();

        $receipt = $this->postJson('/api/v1/purchasing/receipts', [
            'receipt_number' => 'PREC-OVER-001',
            'purchase_invoice_id' => $invoice->json('data.id'),
            'receipt_date' => '2026-09-18',
            'idempotency_key' => 'receipt-over-001',
            'lines' => [[
                'purchase_invoice_line_id' => $invoice->json('data.lines.0.id'),
                'accepted_quantity' => 26,
                'accepted_foc_quantity' => 0,
                'receipt_lot_number' => 'LOT-OVER-001',
                'expiry_date' => '2027-09-01',
            ]],
        ])->assertCreated();

        $this->postJson('/api/v1/purchasing/receipts/'.$receipt->json('data.id').'/confirm')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
    }

    public function test_breached_medicine_receipt_posts_quarantined_stock(): void
    {
        $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('medicine');
        $invoice = $this->createPurchaseInvoice($setup, [
            'invoice_number' => 'PINV-MED-001',
            'quantity' => 12,
            'unit_price' => 15,
        ])->assertCreated();

        $receipt = $this->postJson('/api/v1/purchasing/receipts', [
            'receipt_number' => 'PREC-MED-001',
            'purchase_invoice_id' => $invoice->json('data.id'),
            'receipt_date' => '2026-09-18',
            'idempotency_key' => 'receipt-med-001',
            'lines' => [[
                'purchase_invoice_line_id' => $invoice->json('data.lines.0.id'),
                'accepted_quantity' => 12,
                'accepted_foc_quantity' => 0,
                'target_location' => 'COLD-ROOM',
                'supplier_batch_number' => 'SUP-M-001',
                'receipt_lot_number' => 'LOT-M-001',
                'manufacturing_date' => '2026-09-01',
                'expiry_date' => '2027-09-01',
                'cold_chain_required' => true,
                'cold_chain_status' => 'breached',
                'observed_temperature' => 12.5,
                'temperature_uom' => 'C',
                'exception_reason' => 'Temperature logger exceeded threshold.',
            ]],
        ])->assertCreated();

        $this->postJson('/api/v1/purchasing/receipts/'.$receipt->json('data.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('inventory_stock_lots', [
            'category' => 'medicine',
            'item_id' => $setup['item']->id,
            'receipt_lot_number' => 'LOT-M-001',
            'lot_status' => 'quarantined',
            'cold_chain_required' => true,
            'cold_chain_status' => 'breached',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'category' => 'medicine',
            'item_id' => $setup['item']->id,
            'on_hand_quantity' => 12,
            'quarantined_quantity' => 12,
            'available_quantity' => 0,
        ]);
        $this->assertSame(12.0, (float) InventoryLedgerEntry::query()->where('source_module', 'purchasing')->firstOrFail()->quantity_in);
    }

    /** @param array<string, mixed> $overrides */
    private function createPurchaseInvoice(array $setup, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/purchasing/invoices', [
            'invoice_number' => $overrides['invoice_number'] ?? 'PINV-TEST-001',
            'invoice_date' => '2026-09-18',
            'supplier_id' => $setup['supplier']->id,
            'branch_id' => $setup['branch']->id,
            'lines' => [[
                'category' => $setup['category'],
                'item_id' => $setup['item']->id,
                'purchase_uom_id' => $setup['uom']->id,
                'stock_uom_id' => $setup['uom']->id,
                'conversion_factor' => 1,
                'quantity' => $overrides['quantity'] ?? 100,
                'unit_price' => $overrides['unit_price'] ?? 1,
                'discount_type' => $overrides['discount_type'] ?? null,
                'discount_value' => $overrides['discount_value'] ?? 0,
                'foc_type' => $overrides['foc_type'] ?? null,
                'foc_value' => $overrides['foc_value'] ?? 0,
                'tax_rate' => $overrides['tax_rate'] ?? 0,
                'target_inventory_id' => $setup['inventory']->id,
                'target_location' => $overrides['target_location'] ?? 'MAIN',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function setupPurchasingStock(string $category): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-PUR-'.strtoupper($category),
            'name' => 'Purchasing '.ucfirst($category).' Branch',
            'status' => 'active',
        ]);
        $uom = Uom::query()->create([
            'code' => $category === 'food' ? 'KG-PUR' : 'VIAL-PUR',
            'name' => $category === 'food' ? 'Purchase Kilogram' : 'Purchase Vial',
            'symbol' => $category === 'food' ? 'kg' : 'vial',
            'category' => $category === 'food' ? 'weight' : 'unit',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-PUR-'.strtoupper($category),
            'type' => $category,
            'name' => ucfirst($category).' Purchasing Supplier',
            'phone_number' => '+95 9 700000002',
            'supplied_categories' => [$category],
            'preferred_branch_id' => $branch->id,
            'lead_time_days' => 1,
            'minimum_order_amount' => 0,
            'credit_limit' => 0,
            'opening_balance_type' => 'none',
            'opening_balance' => 0,
            'status' => 'active',
        ]);
        $inventory = Inventory::query()->create([
            'code' => 'INV-PUR-'.strtoupper($category),
            'name' => ucfirst($category).' Purchase Store',
            'type' => $category === 'food' ? 'feed' : 'medicine',
            'branch_id' => $branch->id,
            'allowed_item_categories' => [$category],
            'status' => 'active',
        ]);

        if ($category === 'medicine') {
            $master = Medicine::query()->create([
                'code' => 'MED-PUR-001',
                'name' => 'Cold Chain Vaccine',
                'type' => 'vaccine',
                'category' => 'vaccine',
                'purchase_uom_id' => $uom->id,
                'stock_uom_id' => $uom->id,
                'usage_uom_id' => $uom->id,
                'uom_conversion' => 1,
                'default_supplier_id' => $supplier->id,
                'batch_tracking' => true,
                'expiry_tracking' => true,
                'cold_chain_required' => true,
                'storage_temperature' => '2-8 C',
                'purchase_price' => 15,
                'status' => 'active',
            ]);
        } else {
            $master = Food::query()->create([
                'code' => 'FOD-PUR-001',
                'name' => 'Purchase Feed',
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
        }

        $item = app(ItemRegistryService::class)->syncFromMaster($master);

        return compact('branch', 'uom', 'supplier', 'inventory', 'master', 'item', 'category');
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