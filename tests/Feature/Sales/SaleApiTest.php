<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Farms\Models\AnimalProfile;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashLedgerCategory;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Sales\Models\CustomerReceivableEntry;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_posts_stock_receipt_and_receivable_once_and_cancels_atomically(): void
    {
        $s = $this->fixture();
        $payload = $this->payload($s);
        $draft = $this->postJson('/api/v1/sales', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.gross_amount', '200.00')
            ->assertJsonPath('data.discount_amount', '20.00')
            ->assertJsonPath('data.total_amount', '180.00')
            ->assertJsonPath('data.ar_amount', '150.00')
            ->json('data.id');
        $this->assertEquals(10, $s['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);

        $this->postJson('/api/v1/sales/'.$draft.'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment_status', 'partially_paid');
        $this->assertEquals(7, $s['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseHas('inventory_ledger_entries', ['source_module' => 'sales', 'source_type' => 'sale', 'quantity_out' => 3]);
        $this->assertDatabaseHas('customer_receivable_entries', ['sale_id' => $draft, 'entry_type' => 'sale', 'amount' => '150.00']);
        $this->assertDatabaseHas('cashbook_transactions', ['idempotency_key' => 'sale-'.$draft, 'amount' => '30.00', 'status' => 'confirmed']);
        $this->postJson('/api/v1/sales/'.$draft.'/confirm')->assertOk();
        $this->assertDatabaseCount('inventory_ledger_entries', 1);

        $this->postJson('/api/v1/sales/'.$draft.'/receipts', ['amount' => '50.00', 'cashbook_id' => $s['cashbook']->id, 'cash_category_id' => $s['cashIn']->id, 'idempotency_key' => 'part-one'])
            ->assertOk()->assertJsonPath('data.ar_amount', '100.00')->assertJsonPath('data.paid_amount', '80.00');
        $this->assertSame(-5000, (int) round((float) CustomerReceivableEntry::query()->where('sale_id', $draft)->where('entry_type', 'receipt')->value('amount') * 100));

        $this->postJson('/api/v1/sales/'.$draft.'/cancel', ['reason' => 'Customer order entered incorrectly'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(10, $s['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_ledger_entries', 2);
        $this->assertSame(0, (int) round((float) CustomerReceivableEntry::query()->where('sale_id', $draft)->sum('amount') * 100));
        $this->assertDatabaseHas('cashbook_transactions', ['source_type' => 'sale_receipt_reversal', 'status' => 'confirmed']);
    }

    public function test_confirmation_rechecks_customer_eligibility_and_branch_scope(): void
    {
        $s = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->payload($s))->assertCreated()->json('data.id');
        $s['customer']->update(['status' => 'inactive']);
        $this->postJson('/api/v1/sales/'.$id.'/confirm')->assertNotFound();
        $this->assertEquals(10, $s['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);

        $s['customer']->update(['status' => 'active']);
        $s['user']->branches()->detach($s['branch']->id);
        $this->getJson('/api/v1/sales/'.$id)->assertForbidden();
        $this->postJson('/api/v1/sales/'.$id.'/confirm')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        foreach (['sales.view', 'sales.manage', 'sales.backdate'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo(['sales.view', 'sales.manage']);
        Sanctum::actingAs($user);

        $branch = Branch::query()->create(['code' => 'BR-SALE', 'name' => 'Sale Branch', 'status' => 'active']);
        $user->branches()->attach($branch->id);
        $uom = Uom::query()->create(['code' => 'HD-SALE', 'name' => 'Head', 'symbol' => 'hd', 'category' => 'unit', 'status' => 'active']);
        $inventory = Inventory::query()->create(['code' => 'INV-SALE', 'name' => 'Sale stock', 'type' => 'animal', 'branch_id' => $branch->id, 'allowed_item_categories' => ['animal'], 'status' => 'active']);
        $animal = Animal::query()->create(['code' => 'ANI-SALE', 'tracking_type' => 'batch', 'batch_flock_number' => 'SALE-BATCH-1', 'name' => 'Sale cattle', 'type' => 'cattle', 'category' => 'beef', 'breed' => 'Brahman', 'gender' => 'mixed']);
        $farm = FarmInformation::query()->create(['name' => 'Sale farm', 'branch_id' => $branch->id, 'house_barn' => 'Barn 1', 'pen_cage_pond' => 'Pen 1', 'current_animal_id' => $animal->id]);
        $item = app(ItemRegistryService::class)->syncFromMaster($animal);
        $balance = InventoryBalance::query()->create(['identity_key' => 'SALE-BATCH-BALANCE', 'category' => 'animal', 'item_type' => 'animal', 'item_id' => $item->id, 'branch_id' => $branch->id, 'inventory_id' => $inventory->id, 'farm_information_id' => $farm->id, 'location' => 'Pen 1', 'stock_uom_id' => $uom->id, 'on_hand_quantity' => 10, 'available_quantity' => 10]);
        AnimalProfile::query()->create(['animal_balance_id' => $balance->id, 'initial_quantity' => 10, 'health_status' => 'healthy', 'animal_state' => 'alive']);
        $customer = Customer::query()->create(['code' => 'CUS-SALE', 'type' => 'business', 'name' => 'Sale Customer', 'phone_number' => '+959123456789', 'preferred_branch_id' => $branch->id, 'credit_limit' => 1000, 'status' => 'active']);

        $cashOut = CashLedgerCategory::query()->create(['name' => 'Sale reversal', 'normalized_name' => 'sale reversal', 'direction' => 'out', 'status' => 'active']);
        $cashIn = CashLedgerCategory::query()->create(['name' => 'Sale receipt', 'normalized_name' => 'sale receipt', 'direction' => 'in', 'reversal_category_id' => $cashOut->id, 'status' => 'active']);
        $cashOut->update(['reversal_category_id' => $cashIn->id]);
        $cashbook = Cashbook::query()->create(['branch_id' => $branch->id, 'type' => 'cash', 'name' => 'Sale cashbook', 'normalized_name' => 'sale cashbook', 'currency_code' => 'MMK', 'opening_balance' => '1000.00', 'effective_date' => now()->toDateString(), 'status' => 'active', 'created_by_id' => $user->id]);
        $controls = app(FinancialControlService::class);
        $controls->configure($branch->id, ['default_currency' => 'MMK', 'enabled_currencies' => ['MMK'], 'timezone' => config('app.timezone'), 'require_open_period' => true, 'require_approval_limit' => true], $user);
        $controls->createPeriod(['branch_id' => $branch->id, 'name' => 'Sale test', 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString()], $user);
        $controls->setLimit($branch->id, ['user_id' => $user->id, 'currency_code' => 'MMK', 'maximum_amount' => '1000.00', 'allow_self_approval' => true], $user);

        return compact('user', 'branch', 'uom', 'inventory', 'animal', 'farm', 'item', 'balance', 'customer', 'cashbook', 'cashIn', 'cashOut');
    }

    private function payload(array $s): array
    {
        return ['idempotency_key' => 'sale-create-1', 'branch_id' => $s['branch']->id, 'farm_information_id' => $s['farm']->id, 'inventory_balance_id' => $s['balance']->id, 'customer_id' => $s['customer']->id, 'sale_date' => now()->toDateString(), 'quantity' => 2, 'foc_quantity' => 1, 'unit_price' => '100.00', 'discount_type' => 'percentage', 'discount_value' => '10', 'paid_amount' => '30.00', 'cashbook_id' => $s['cashbook']->id, 'cash_category_id' => $s['cashIn']->id];
    }

    public function test_receipt_retries_cannot_change_cashbook_and_cross_currency_receipts_are_rejected(): void
    {
        $s = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->payload($s))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/'.$id.'/confirm')->assertOk();
        $data = ['amount' => '10.00', 'cashbook_id' => $s['cashbook']->id, 'cash_category_id' => $s['cashIn']->id, 'idempotency_key' => 'receipt-1'];
        $this->postJson('/api/v1/sales/'.$id.'/receipts', $data)->assertOk();
        $other = Cashbook::query()->create(['branch_id' => $s['branch']->id, 'type' => 'bank', 'name' => 'USD bank', 'normalized_name' => 'usd bank', 'currency_code' => 'USD', 'opening_balance' => '0.00', 'effective_date' => now()->toDateString(), 'status' => 'active', 'created_by_id' => $s['user']->id]);
        $this->postJson('/api/v1/sales/'.$id.'/receipts', array_merge($data, ['cashbook_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/sales/'.$id.'/receipts', array_merge($data, ['cashbook_id' => $other->id, 'idempotency_key' => 'receipt-2']))->assertUnprocessable()->assertJsonValidationErrors('cashbook_id');
        $this->assertDatabaseCount('cashbook_transactions', 2);
    }
}
