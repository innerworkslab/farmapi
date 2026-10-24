<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Food;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Supplier;
use App\Modules\Setup\Models\Uom;
use App\Modules\Setup\Services\FoodService;
use App\Modules\Setup\Services\MedicineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchasingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_lines_exclude_receipt_destination_fields_on_create_update_and_reads(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $s = $this->setupPurchasingStock('food');
        $user->branches()->attach($s['branch']->id);
        foreach (['target_inventory_id', 'target_location'] as $column) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('purchase_invoice_lines', $column));
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('purchase_receipt_lines', $column));
        }
        $invoice = $this->createPurchaseInvoice($s)->assertCreated()
            ->assertJsonMissingPath('data.lines.0.target_inventory_id')
            ->assertJsonMissingPath('data.lines.0.target_location');
        $id = $invoice->json('data.id');
        $body = ['invoice_date' => '2026-09-18', 'supplier_id' => $s['supplier']->id, 'branch_id' => $s['branch']->id, 'cashbook_id' => $s['cashbook']->id, 'paid_amount' => 0,
            'lines' => [['category' => 'food', 'item_id' => $s['item']->id, 'quantity' => 2, 'unit_price' => 2]]];
        $this->postJson('/api/v1/purchasing/invoices/'.$id, $body)->assertOk()
            ->assertJsonPath('data.total_amount', '4.00')
            ->assertJsonMissingPath('data.lines.0.target_inventory_id')->assertJsonMissingPath('data.lines.0.target_location');
        $this->getJson('/api/v1/purchasing/invoices/'.$id)->assertOk()
            ->assertJsonMissingPath('data.lines.0.target_inventory_id')->assertJsonMissingPath('data.lines.0.target_location');
        $this->getJson('/api/v1/purchasing/invoices')->assertOk()
            ->assertJsonMissingPath('data.0.lines.0.target_inventory_id')->assertJsonMissingPath('data.0.lines.0.target_location');
        $body['lines'][0] += ['target_inventory_id' => $s['inventory']->id, 'target_location' => 'OLD'];
        $this->postJson('/api/v1/purchasing/invoices', $body)->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.target_inventory_id', 'lines.0.target_location']);
        $this->postJson('/api/v1/purchasing/invoices/'.$id, $body)->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.target_inventory_id', 'lines.0.target_location']);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
    }

    public function test_stock_receipts_require_their_own_inventory_and_default_location_to_main(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $s = $this->setupPurchasingStock('food');
        $user->branches()->attach($s['branch']->id);
        $invoice = $this->createPurchaseInvoice($s)->assertCreated();
        $body = ['purchase_invoice_id' => $invoice->json('data.id'), 'receipt_date' => '2026-09-18',
            'lines' => [['purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'accepted_quantity' => 1,
                'receipt_lot_number' => 'RECEIPT-DESTINATION', 'expiry_date' => '2099-01-01']]];
        $this->postJson('/api/v1/purchasing/receipts', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.target_inventory_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $destination = Inventory::create(['code' => 'SECOND', 'name' => 'Selected receiving store', 'branch_id' => $s['branch']->id, 'cashbook_id' => $s['cashbook']->id, 'paid_amount' => 0, 'type' => 'feed', 'status' => 'active']);
        $body['lines'][0]['target_inventory_id'] = $destination->id;
        $receipt = $this->postJson('/api/v1/purchasing/receipts', $body)->assertCreated()
            ->assertJsonPath('data.lines.0.target_inventory_id', $destination->id)
            ->assertJsonPath('data.lines.0.target_location', 'MAIN')->json('data.id');
        $this->postJson('/api/v1/purchasing/receipts/'.$receipt.'/confirm')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['inventory_id' => $destination->id, 'location' => 'MAIN', 'on_hand_quantity' => 1]);
        $this->assertDatabaseMissing('inventory_balances', ['inventory_id' => $s['inventory']->id]);
    }

    public function test_receipt_confirmation_rechecks_destination_ownership(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        $user->branches()->attach($setup['branch']->id);
        $invoice = $this->createPurchaseInvoice($setup)->assertCreated();
        $receipt = $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_invoice_id' => $invoice->json('data.id'), 'receipt_date' => '2026-09-18',
            'lines' => [['purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'target_inventory_id' => $setup['inventory']->id, 'accepted_quantity' => 1, 'receipt_lot_number' => 'DESTINATION-LOT', 'manufacturing_date' => '2026-09-01', 'expiry_date' => '2027-09-01']],
        ])->assertCreated()->json('data.id');
        $other = Branch::query()->create(['code' => 'MOVED', 'name' => 'Moved', 'status' => 'active']);
        $setup['inventory']->update(['branch_id' => $other->id]);
        $this->postJson('/api/v1/purchasing/receipts/'.$receipt.'/confirm')->assertForbidden();
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->assertDatabaseHas('purchase_receipts', ['id' => $receipt, 'status' => 'draft']);
    }

    public function test_purchasing_denies_foreign_records_and_posting_after_branch_access_is_revoked(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        $user->branches()->attach($setup['branch']->id);
        $invoice = $this->createPurchaseInvoice($setup)->assertCreated();
        $id = $invoice->json('data.id');
        $payload = [
            'purchase_invoice_id' => $id,
            'receipt_date' => '2026-09-18',
            'lines' => [['purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'target_inventory_id' => $setup['inventory']->id, 'accepted_quantity' => 1]],
        ];
        $receipt = $this->postJson('/api/v1/purchasing/receipts', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/purchasing/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/purchasing/receipts')->assertOk()->assertJsonCount(1, 'data');
        $other = Branch::query()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $user->branches()->sync([$other->id]);
        $this->getJson('/api/v1/purchasing/invoices?branch_id='.$setup['branch']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchasing/receipts?purchase_invoice_id='.$id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchasing/invoices/'.$id)->assertForbidden();
        $this->getJson('/api/v1/purchasing/receipts/'.$receipt)->assertForbidden();
        $this->postJson('/api/v1/purchasing/invoices/'.$id.'/cancel', ['reason' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/api/v1/purchasing/receipts', $payload)->assertForbidden();
        $this->postJson('/api/v1/purchasing/receipts/'.$receipt.'/confirm')->assertForbidden();
        $this->createPurchaseInvoice($setup, ['invoice_number' => 'DENIED-NEW'])->assertForbidden();
        $this->assertDatabaseCount('purchase_invoices', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->assertDatabaseHas('purchase_receipts', ['id' => $receipt, 'status' => 'draft']);
        $user->branches()->detach();
        $this->getJson('/api/v1/purchasing/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->createPurchaseInvoice($setup, ['invoice_number' => 'DENIED-NEW'])->assertForbidden();
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $this->getJson('/api/v1/purchasing/invoices/'.$id)->assertOk();
        $this->getJson('/api/v1/purchasing/receipts/'.$receipt)->assertOk();
    }

    public function test_purchase_destination_overrides_cannot_cross_branches(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        $user->branches()->attach($setup['branch']->id);
        $invoice = $this->createPurchaseInvoice($setup)->assertCreated();
        $other = Branch::query()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $foreign = Inventory::query()->create(['code' => 'OTHER', 'name' => 'Other store', 'type' => 'feed', 'branch_id' => $other->id, 'status' => 'active']);
        $payload = [
            'purchase_invoice_id' => $invoice->json('data.id'), 'receipt_date' => '2026-09-18',
            'lines' => [['purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'accepted_quantity' => 1, 'target_inventory_id' => $foreign->id]],
        ];
        $this->postJson('/api/v1/purchasing/receipts', $payload)->assertForbidden();
        $user->branches()->attach($other->id);
        $this->postJson('/api/v1/purchasing/receipts', $payload)->assertUnprocessable()->assertJsonValidationErrors('inventory_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
    }

    public function test_invoice_update_checks_both_existing_and_proposed_branch(): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        $user->branches()->attach($setup['branch']->id);
        $invoice = $this->createPurchaseInvoice($setup)->assertCreated()->json('data.id');
        $payload = ['invoice_date' => '2026-09-18', 'supplier_id' => $setup['supplier']->id, 'branch_id' => $setup['branch']->id, 'cashbook_id' => $setup['cashbook']->id, 'paid_amount' => 0,
            'lines' => [['category' => 'food', 'item_id' => $setup['item']->id, 'quantity' => 1, 'unit_price' => 1]]];
        $other = Branch::query()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->postJson('/api/v1/purchasing/invoices/'.$invoice, array_replace($payload, ['branch_id' => $other->id]))->assertForbidden();
        $user->branches()->sync([$other->id]);
        $this->postJson('/api/v1/purchasing/invoices/'.$invoice, array_replace($payload, ['branch_id' => $other->id]))->assertForbidden();
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice, 'branch_id' => $setup['branch']->id]);
    }

    public function test_purchase_invoice_save_does_not_post_inventory_until_receipt_confirmation(): void
    {
        $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);

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
                'purchase_invoice_line_id' => $invoiceLineId, 'target_inventory_id' => $setup['inventory']->id,
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
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);
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
                'purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'target_inventory_id' => $setup['inventory']->id,
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
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);
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
                'purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'target_inventory_id' => $setup['inventory']->id,
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
    public function test_purchase_prices_discounts_and_tax_round_as_exact_decimals(): void
    {
        $this->actingWithPurchasingPermissions();
        $setup = $this->setupPurchasingStock('food');
        auth()->user()->branches()->syncWithoutDetaching([$setup['branch']->id]);
        $this->createPurchaseInvoice($setup, ['quantity' => '4.5', 'unit_price' => '0.33', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '7.5'])
            ->assertCreated()->assertJsonPath('data.gross_amount', '1.49')->assertJsonPath('data.discount_amount', '0.15')
            ->assertJsonPath('data.tax_amount', '0.10')->assertJsonPath('data.total_amount', '1.44');
    }

    public static function unitStockChoices(): array
    {
        return [['food', false], ['food', true], ['medicine', false], ['medicine', true]];
    }

    #[DataProvider('unitStockChoices')]
    public function test_receiving_derives_stock_conversion_and_rejects_unit_overrides(string $category, bool $stockInUsage): void
    {
        $user = $this->actingWithPurchasingPermissions();
        $s = $this->setupPurchasingStock($category);
        $user->branches()->attach($s['branch']->id);
        $usage = Uom::create(['code' => 'USE', 'name' => 'Usage unit', 'symbol' => 'use', 'category' => 'quantity', 'status' => 'active']);
        $stockId = $stockInUsage ? $usage->id : $s['uom']->id;
        $s['master']->update(['stock_uom_id' => $stockId, $category === 'food' ? 'consumption_uom_id' : 'usage_uom_id' => $usage->id, 'uom_conversion' => 1000]);
        app(ItemRegistryService::class)->syncFromMaster($s['master']);
        $factor = $stockInUsage ? 1000 : 1;
        $payload = ['invoice_date' => now()->toDateString(), 'supplier_id' => $s['supplier']->id, 'branch_id' => $s['branch']->id, 'cashbook_id' => $s['cashbook']->id, 'paid_amount' => 0,
            'lines' => [['category' => $category, 'item_id' => $s['item']->id, 'quantity' => 2, 'unit_price' => '10.00']]];
        foreach ([['conversion_factor' => 7], ['purchase_uom_id' => $usage->id], ['stock_uom_id' => $stockInUsage ? $s['uom']->id : $usage->id]] as $override) {
            $bad = $payload;
            $bad['lines'][0] = array_replace($bad['lines'][0], $override);
            $this->postJson('/api/v1/purchasing/invoices', $bad)->assertUnprocessable();
        }
        $invoice = $this->postJson('/api/v1/purchasing/invoices', $payload)->assertCreated()
            ->assertJsonPath('data.lines.0.stock_uom_id', $stockId)
            ->assertJsonPath('data.lines.0.conversion_factor', $factor);
        // The purchase snapshot blocks subsequent definition changes, even before receiving.
        $service = app($category === 'food' ? FoodService::class : MedicineService::class);
        try {
            $service->update($s['master'], ['uom_conversion' => 500]);
            $this->fail('Referenced item conversion was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('uom_conversion', $exception->errors());
        }
        $receipt = $this->postJson('/api/v1/purchasing/receipts', ['purchase_invoice_id' => $invoice->json('data.id'), 'receipt_date' => now()->toDateString(), 'idempotency_key' => 'uom-receive',
            'lines' => [['purchase_invoice_line_id' => $invoice->json('data.lines.0.id'), 'target_inventory_id' => $s['inventory']->id, 'accepted_quantity' => 2, 'receipt_lot_number' => 'UOM-LOT', 'expiry_date' => '2099-01-01', 'cold_chain_status' => 'compliant', 'observed_temperature' => 4]]])->assertCreated();
        $url = '/api/v1/purchasing/receipts/'.$receipt->json('data.id').'/confirm';
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item']->id, 'stock_uom_id' => $stockId, 'on_hand_quantity' => 2 * $factor]);
        $this->assertDatabaseHas('inventory_ledger_entries', ['item_id' => $s['item']->id, 'original_uom_id' => $s['uom']->id, 'original_quantity' => 2, 'quantity_in' => 2 * $factor, 'conversion_factor' => $factor]);
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
    }

    private function createPurchaseInvoice(array $setup, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/purchasing/invoices', [
            'invoice_number' => $overrides['invoice_number'] ?? 'PINV-TEST-001',
            'invoice_date' => '2026-09-18',
            'supplier_id' => $setup['supplier']->id,
            'branch_id' => $setup['branch']->id, 'cashbook_id' => $setup['cashbook']->id, 'paid_amount' => 0,
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

        $this->seed(\Database\Seeders\PurchasePaymentCategorySeeder::class);
        $cashbook = \App\Modules\Financial\Models\Cashbook::create(['branch_id' => $branch->id, 'name' => 'Purchase cash', 'normalized_name' => 'purchase cash', 'type' => 'cash', 'currency_code' => 'MMK', 'opening_balance' => 0, 'effective_date' => '2026-01-01', 'status' => 'active']);

        return compact('branch', 'uom', 'supplier', 'inventory', 'master', 'item', 'category', 'cashbook');
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
