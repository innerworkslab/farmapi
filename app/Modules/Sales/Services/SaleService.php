<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\CashLedgerCategory;
use App\Modules\Financial\Models\FinancialBranchSetting;
use App\Modules\Financial\Services\CashbookTransactionService;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Sales\Models\CustomerReceivableEntry;
use App\Modules\Sales\Models\Sale;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Models\FarmInformation;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private readonly CashbookTransactionService $cashbookTransactions) {}

    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $q = Sale::query()->with(['customer', 'farm', 'balance.item', 'balance.profile']);
        BranchAccess::scope($q, $actor, 'branch_id');
        $q->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filters['farm_information_id'] ?? null, fn (Builder $q, $v) => $q->where('farm_information_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, $v) => $q->where('payment_status', $v))
            ->when($filters['from_date'] ?? null, fn (Builder $q, $v) => $q->whereDate('sale_date', '>=', $v))
            ->when($filters['to_date'] ?? null, fn (Builder $q, $v) => $q->whereDate('sale_date', '<=', $v))
            ->when($filters['search'] ?? null, function (Builder $q, string $v): void {
                $q->where(fn (Builder $n) => $n->where('sale_number', 'like', "%{$v}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
                    ->orWhereHas('balance.item', fn (Builder $i) => $i->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")));
            });

        return $q->orderByDesc('sale_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function find(Sale $sale, User $actor): Sale
    {
        $sale = Sale::query()->with(['customer', 'farm.branch', 'balance.item', 'balance.profile', 'receivableEntries'])->findOrFail($sale->id);
        BranchAccess::authorize($actor, (int) $sale->branch_id);

        return $sale;
    }

    public function create(array $data, User $actor): Sale
    {
        return DB::transaction(function () use ($data, $actor): Sale {
            $balance = $this->eligibleBalance((int) $data['inventory_balance_id'], (int) $data['farm_information_id'], (int) $data['branch_id'], $actor, false);
            $customer = $this->eligibleCustomer((int) $data['customer_id'], (int) $data['branch_id'], $actor);
            $date = $data['sale_date'] ?? now()->toDateString();
            $this->assertSaleDate($date, $actor);
            $this->assertDraftAvailability($balance, (int) $data['quantity'], (int) ($data['foc_quantity'] ?? 0));
            [$gross, $discount, $total, $paid, $ar, $paymentStatus] = $this->calculate($data);
            $year = (int) substr($date, 0, 4);
            DB::table('sale_sequences')->insertOrIgnore(['branch_id' => $data['branch_id'], 'sequence_year' => $year, 'last_number' => 0]);
            $sequence = DB::table('sale_sequences')->where('branch_id', $data['branch_id'])->where('sequence_year', $year)->lockForUpdate()->first();
            $number = (int) $sequence->last_number + 1;
            DB::table('sale_sequences')->where('branch_id', $data['branch_id'])->where('sequence_year', $year)->update(['last_number' => $number]);
            $sale = Sale::query()->create([
                'sale_number' => sprintf('SAL-%s-B%d-%06d', $year, $data['branch_id'], $number), 'idempotency_key' => $data['idempotency_key'],
                'branch_id' => $data['branch_id'], 'farm_information_id' => $data['farm_information_id'], 'inventory_balance_id' => $balance->id,
                'currency_code' => $data['currency_code'] ?? (isset($data['cashbook_id']) ? Cashbook::query()->findOrFail($data['cashbook_id'])->currency_code : FinancialBranchSetting::query()->where('branch_id', $data['branch_id'])->value('default_currency')),
                'customer_id' => $customer->id, 'sale_date' => $date, 'quantity' => $data['quantity'], 'foc_quantity' => $data['foc_quantity'] ?? 0,
                'unit_price' => $data['unit_price'], 'discount_type' => $data['discount_type'] ?? 'none', 'discount_value' => $data['discount_value'] ?? 0,
                'gross_amount' => $gross, 'discount_amount' => $discount, 'total_amount' => $total, 'paid_amount' => $paid, 'ar_amount' => $ar,
                'payment_status' => $paymentStatus, 'status' => 'draft', 'cashbook_id' => $data['cashbook_id'] ?? null,
                'cash_category_id' => $data['cash_category_id'] ?? null, 'created_by_id' => $actor->id, 'version' => 1,
            ]);

            return $this->find($sale, $actor);
        });
    }

    public function updateDraft(Sale $sale, array $data, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $data, $actor): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            BranchAccess::authorize($actor, (int) $sale->branch_id);
            if ($sale->status !== 'draft') {
                throw ValidationException::withMessages(['sale' => 'Only draft sales can be edited.']);
            }
            $payload = array_merge($sale->only(['branch_id', 'farm_information_id', 'inventory_balance_id', 'customer_id', 'sale_date', 'quantity', 'foc_quantity', 'unit_price', 'discount_type', 'discount_value', 'paid_amount', 'cashbook_id', 'cash_category_id', 'currency_code']), $data);
            $balance = $this->eligibleBalance((int) $payload['inventory_balance_id'], (int) $payload['farm_information_id'], (int) $sale->branch_id, $actor, false);
            $customer = $this->eligibleCustomer((int) $payload['customer_id'], (int) $sale->branch_id, $actor);
            $this->assertDraftAvailability($balance, (int) $payload['quantity'], (int) $payload['foc_quantity']);
            $this->assertSaleDate($payload['sale_date'] instanceof \DateTimeInterface ? $payload['sale_date']->format('Y-m-d') : (string) $payload['sale_date'], $actor);
            [$gross, $discount, $total, $paid, $ar, $paymentStatus] = $this->calculate($payload);
            foreach (['farm_information_id', 'inventory_balance_id', 'customer_id', 'sale_date', 'quantity', 'foc_quantity', 'unit_price', 'discount_type', 'discount_value', 'paid_amount', 'cashbook_id', 'cash_category_id', 'currency_code'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $sale->{$field} = $payload[$field];
                }
            }
            $sale->gross_amount = $gross;
            $sale->discount_amount = $discount;
            $sale->total_amount = $total;
            $sale->paid_amount = $paid;
            $sale->ar_amount = $ar;
            $sale->payment_status = $paymentStatus;
            $sale->version++;
            $sale->save();

            return $this->find($sale, $actor);
        });
    }

    public function confirm(Sale $sale, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $actor): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            BranchAccess::authorize($actor, (int) $sale->branch_id);
            if ($sale->status === 'confirmed') {
                return $this->find($sale, $actor);
            }
            if ($sale->status !== 'draft') {
                throw ValidationException::withMessages(['sale' => 'Only draft sales can be confirmed.']);
            }
            app(FinancialControlService::class)->assertDate((int) $sale->branch_id, $sale->sale_date->toDateString(), $actor, false);
            if (! $sale->currency_code) {
                throw ValidationException::withMessages(['currency_code' => 'Set an explicit sale currency or configure the branch default before confirming.']);
            }
            $customer = $this->eligibleCustomer((int) $sale->customer_id, (int) $sale->branch_id, $actor, true);
            if (Sale::query()->where('customer_id', $customer->id)->where('status', 'confirmed')->where(function ($q) use ($sale) {
                $q->whereNull('currency_code')->orWhere('currency_code', '<>', $sale->currency_code);
            })->exists()) {
                throw ValidationException::withMessages(['currency_code' => 'Customer receivables and credit limits use one currency. Resolve historical currency or use the existing customer currency.']);
            }
            $balance = $this->eligibleBalance((int) $sale->inventory_balance_id, (int) $sale->farm_information_id, (int) $sale->branch_id, $actor, true);
            $this->assertDraftAvailability($balance, (int) $sale->quantity, (int) $sale->foc_quantity);
            $data = $sale->only(['quantity', 'foc_quantity', 'unit_price', 'discount_type', 'discount_value', 'paid_amount']);
            [$gross, $discount, $total, $paid, $ar, $paymentStatus] = $this->calculate($data);
            if ($paid > 0) {
                $this->assertReceiptConfig($sale);
            }
            if ($ar > 0) {
                $this->assertCreditLimit($customer, $ar);
            }
            $sale->gross_amount = $gross;
            $sale->discount_amount = $discount;
            $sale->total_amount = $total;
            $sale->paid_amount = $paid;
            $sale->ar_amount = $ar;
            $sale->payment_status = $paymentStatus;
            $sale->customer_snapshot = ['code' => $customer->code, 'name' => $customer->name, 'contact_person' => $customer->contact_person, 'phone_number' => $customer->phone_number, 'alternative_phone' => $customer->alternative_phone, 'delivery_address' => $customer->delivery_address, 'township' => $customer->township, 'state_region' => $customer->state_region, 'price_level' => $customer->price_level];
            $this->postStock($sale, $balance, -((int) $sale->quantity + (int) $sale->foc_quantity), $actor, 'sale');
            if ($paid > 0) {
                $this->postReceipt($sale, $actor);
            }
            if ($ar > 0) {
                CustomerReceivableEntry::query()->create(['customer_id' => $customer->id, 'sale_id' => $sale->id, 'branch_id' => $sale->branch_id, 'entry_type' => 'sale', 'amount' => $ar, 'reference' => $sale->sale_number, 'idempotency_key' => 'sale-'.$sale->id.'-ar', 'created_by_id' => $actor->id, 'business_date' => $sale->sale_date]);
            }
            $sale->status = 'confirmed';
            $sale->confirmed_by_id = $actor->id;
            $sale->confirmed_at = now();
            $sale->version++;
            $sale->save();

            return $this->find($sale, $actor);
        });
    }

    public function cancel(Sale $sale, string $reason, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $actor): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            BranchAccess::authorize($actor, (int) $sale->branch_id);
            if ($sale->status !== 'confirmed') {
                throw ValidationException::withMessages(['sale' => 'Only confirmed sales can be cancelled.']);
            }
            $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($sale->inventory_balance_id);
            $this->postStock($sale, $balance, (int) $sale->quantity + (int) $sale->foc_quantity, $actor, 'sale_reversal');
            $receipts = CashbookTransaction::query()->where('source_type', 'sale_receipt')->where(function ($q) use ($sale): void {
                $q->where('idempotency_key', 'sale-'.$sale->id)->orWhere('idempotency_key', 'like', 'sale-receipt-'.$sale->id.'-%');
            })->where('status', 'confirmed')->orderByDesc('id')->get();
            foreach ($receipts as $transaction) {
                $this->cashbookTransactions->reverseForModule($transaction, 'sale_receipt', $reason, $actor);
            }
            if ((float) $sale->ar_amount > 0) {
                CustomerReceivableEntry::query()->create(['customer_id' => $sale->customer_id, 'sale_id' => $sale->id, 'branch_id' => $sale->branch_id, 'entry_type' => 'reversal', 'amount' => -$sale->ar_amount, 'reference' => $sale->sale_number.'-REV', 'idempotency_key' => 'sale-'.$sale->id.'-ar-reversal', 'created_by_id' => $actor->id, 'business_date' => $sale->sale_date]);
            }
            $sale->status = 'cancelled';
            $sale->cancellation_reason = $reason;
            $sale->cancelled_by_id = $actor->id;
            $sale->cancelled_at = now();
            $sale->version++;
            $sale->save();

            return $this->find($sale, $actor);
        });
    }

    public function deleteDraft(Sale $sale, User $actor): void
    {
        DB::transaction(function () use ($sale, $actor): void {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            BranchAccess::authorize($actor, (int) $sale->branch_id);
            if ($sale->status !== 'draft') {
                throw ValidationException::withMessages(['sale' => 'Only draft sales can be deleted.']);
            }
            $sale->delete();
        });
    }

    public function receive(Sale $sale, array $data, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $data, $actor): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            BranchAccess::authorize($actor, (int) $sale->branch_id);
            if ($sale->status !== 'confirmed') {
                throw ValidationException::withMessages(['sale' => 'Receipts can only be applied to confirmed sales.']);
            }
            $key = 'sale-receipt-'.$sale->id.'-'.sha1($data['idempotency_key']);
            $existing = CustomerReceivableEntry::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                $cash = CashbookTransaction::query()->findOrFail($existing->cashbook_transaction_id);
                if ($this->minor($existing->amount) !== -$this->minor($data['amount']) || (int) $cash->cashbook_id !== (int) $data['cashbook_id'] || (int) $cash->category_id !== (int) $data['cash_category_id']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This receipt key was already used for different receipt data.']);
                }

                return $this->find($sale, $actor);
            }
            $amountMinor = $this->minor($data['amount']);
            $balanceMinor = $this->minor($sale->ar_amount);
            if ($amountMinor <= 0 || $amountMinor > $balanceMinor) {
                throw ValidationException::withMessages(['amount' => 'Receipt must be positive and cannot exceed the outstanding receivable of '.$sale->ar_amount.'.']);
            }
            if (! Cashbook::query()->whereKey($data['cashbook_id'])->where('branch_id', $sale->branch_id)->where('currency_code', $sale->currency_code)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook in the sale branch and currency is required.']);
            }
            if (! CashLedgerCategory::query()->whereKey($data['cash_category_id'])->where('direction', 'in')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['cash_category_id' => 'An active cash-in category is required.']);
            }
            $cash = $this->cashbookTransactions->postForModule(['cashbook_id' => $data['cashbook_id'], 'category_id' => $data['cash_category_id'], 'business_date' => app(FinancialControlService::class)->businessDate((int) $sale->branch_id), 'direction' => 'in', 'amount' => $data['amount'], 'description' => 'Receivable payment for '.$sale->sale_number, 'external_reference' => $sale->sale_number.'-RCPT-'.Str::upper(Str::substr(sha1($data['idempotency_key']), 0, 10)), 'idempotency_key' => $key, 'created_by_id' => $sale->created_by_id], 'sale_receipt', $actor);
            CustomerReceivableEntry::query()->create(['customer_id' => $sale->customer_id, 'sale_id' => $sale->id, 'branch_id' => $sale->branch_id, 'cashbook_transaction_id' => $cash->id, 'entry_type' => 'receipt', 'amount' => '-'.$this->money($amountMinor), 'reference' => $cash->reference, 'idempotency_key' => $key, 'created_by_id' => $actor->id, 'business_date' => $cash->business_date]);
            $sale->paid_amount = $this->money($this->minor($sale->paid_amount) + $amountMinor);
            $sale->ar_amount = $this->money($balanceMinor - $amountMinor);
            $sale->payment_status = $balanceMinor === $amountMinor ? 'paid' : 'partially_paid';
            $sale->version++;
            $sale->save();

            return $this->find($sale, $actor);
        });
    }

    public function farms(int $branchId, User $actor)
    {
        BranchAccess::authorize($actor, $branchId);

        return FarmInformation::query()->with('branch')->where('branch_id', $branchId)->whereHas('branch', fn ($q) => $q->where('status', Branch::STATUS_ACTIVE))->whereDoesntHave('setting', fn ($q) => $q->where('status', '<>', 'active'))->orderBy('name')->get();
    }

    public function batches(int $farmId, User $actor)
    {
        $farm = FarmInformation::query()->with(['branch', 'setting'])->findOrFail($farmId);
        BranchAccess::authorize($actor, (int) $farm->branch_id);
        if ($farm->branch?->status !== Branch::STATUS_ACTIVE || ($farm->setting?->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages(['farm_information_id' => 'An active farm is required.']);
        }

        return InventoryBalance::query()->with(['item', 'profile'])->where('farm_information_id', $farmId)->where('category', 'animal')->where('on_hand_quantity', '>', 0)->whereHas('item', fn ($q) => $q->where('status', 'active'))->get()->filter(fn ($b) => $this->batchEligible($b))->values()->map(fn ($b) => ['id' => $b->id, 'batch_number' => $b->item?->code, 'animal_type' => $b->item?->name, 'branch_id' => $b->branch_id, 'farm_information_id' => $b->farm_information_id, 'on_hand_quantity' => (int) $b->on_hand_quantity, 'reserved_quantity' => (int) $b->reserved_quantity, 'available_quantity' => (int) $b->available_quantity, 'health_status' => $b->profile?->health_status]);
    }

    public function customers(int $branchId, User $actor, ?string $search = null)
    {
        BranchAccess::authorize($actor, $branchId);

        return Customer::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('preferred_branch_id')->orWhere('preferred_branch_id', $branchId))
            ->when($search, fn ($q, $s) => $q->where(fn ($n) => $n->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('phone_number', 'like', "%{$s}%")))
            ->orderBy('name')->limit(50)->get(['id', 'code', 'name', 'contact_person', 'phone_number', 'preferred_branch_id', 'price_level', 'credit_limit', 'status']);
    }

    private function eligibleCustomer(int $id, int $branchId, User $actor, bool $lock = false): Customer
    {
        $query = Customer::query()->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }
        $customer = $query->findOrFail($id);
        BranchAccess::authorize($actor, $branchId);
        if ($customer->preferred_branch_id && (int) $customer->preferred_branch_id !== $branchId) {
            throw ValidationException::withMessages(['customer_id' => 'Customer is assigned to another branch.']);
        }

        return $customer;
    }

    private function eligibleBalance(int $id, int $farmId, int $branchId, User $actor, bool $lock): InventoryBalance
    {
        $query = InventoryBalance::query()->with(['item', 'profile', 'farmInformation.setting'])->where('category', 'animal');
        if ($lock) {
            $query->lockForUpdate();
        }
        $balance = $query->findOrFail($id);
        BranchAccess::locations($actor, $branchId, (int) $balance->inventory_id, $farmId);
        if ((int) $balance->branch_id !== $branchId || (int) $balance->farm_information_id !== $farmId) {
            throw ValidationException::withMessages(['inventory_balance_id' => 'The batch must belong to the selected branch and farm.']);
        }
        $farm = FarmInformation::query()->with(['branch', 'setting'])->findOrFail($farmId);
        if ((int) $farm->branch_id !== $branchId || $farm->branch?->status !== Branch::STATUS_ACTIVE || ($farm->setting?->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages(['farm_information_id' => 'An active farm in the selected branch is required.']);
        }
        if (! $this->batchEligible($balance)) {
            throw ValidationException::withMessages(['inventory_balance_id' => 'This batch is not eligible for sale.']);
        }
        if (abs((float) $balance->on_hand_quantity - round((float) $balance->on_hand_quantity)) > 0.000001 || abs((float) $balance->available_quantity - round((float) $balance->available_quantity)) > 0.000001) {
            throw ValidationException::withMessages(['inventory_balance_id' => 'Animal batch stock must be a whole-animal quantity.']);
        }

        return $balance;
    }

    private function batchEligible(InventoryBalance $balance): bool
    {
        $profile = $balance->profile;
        $animal = $balance->item?->itemable;
        $health = $profile?->health_status ?? 'healthy';
        $state = $profile?->animal_state ?? 'alive';
        $withdrawalEnd = $profile?->withdrawal_end_date;

        return $balance->category === 'animal' && $balance->item?->status === 'active' && $animal?->tracking_type === 'batch' && (float) $balance->available_quantity > 0 &&
            in_array($health, ['healthy', 'recovered'], true) && ! in_array($state, ['dead', 'closed', 'quarantined'], true) && (! $withdrawalEnd || $withdrawalEnd->toDateString() < now()->toDateString());
    }

    private function assertDraftAvailability(InventoryBalance $balance, int $quantity, int $foc): void
    {
        if ($quantity <= 0 || $foc < 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number and FOC quantity must be zero or more.']);
        }
        if ($quantity + $foc > (int) $balance->available_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Paid and FOC quantity exceed the current available batch quantity ('.(int) $balance->available_quantity.').']);
        }
    }

    private function calculate(array $data): array
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        $foc = (int) ($data['foc_quantity'] ?? 0);
        $unit = $this->minor($data['unit_price'] ?? 0);
        $paid = $this->minor($data['paid_amount'] ?? 0);
        if ($quantity <= 0 || $foc < 0 || $unit <= 0 || $paid < 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be positive, FOC must be non-negative, unit price must be positive, and paid amount cannot be negative.']);
        }
        if ($unit > intdiv(PHP_INT_MAX, $quantity)) {
            throw ValidationException::withMessages(['unit_price' => 'Sale amount exceeds the supported limit.']);
        }
        $gross = $quantity * $unit;
        if ($gross > 999999999999999999) {
            throw ValidationException::withMessages(['unit_price' => 'Sale amount exceeds the supported limit.']);
        }
        $type = $data['discount_type'] ?? 'none';
        $value = $this->minor($data['discount_value'] ?? 0);
        if ($value < 0) {
            throw ValidationException::withMessages(['discount_value' => 'Discount cannot be negative.']);
        }
        if ($type === 'percentage') {
            if ($value > 10000) {
                throw ValidationException::withMessages(['discount_value' => 'Percentage discount must be between 0 and 100.']);
            }
            $discount = intdiv($gross, 10000) * $value + intdiv(($gross % 10000) * $value + 5000, 10000);
        } elseif (in_array($type, ['fixed', 'fixed_amount'], true)) {
            $discount = $value;
        } elseif ($type === 'none' && $value === 0) {
            $discount = 0;
        } else {
            throw ValidationException::withMessages(['discount_type' => 'Discount type must be none, percentage, or fixed.']);
        }
        if ($discount > $gross) {
            throw ValidationException::withMessages(['discount_value' => 'Discount cannot exceed the gross sale amount.']);
        }
        $total = $gross - $discount;
        if ($paid > $total) {
            throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the sale total.']);
        }
        $ar = $total - $paid;
        $status = $paid === 0 ? 'unpaid' : ($ar === 0 ? 'paid' : 'partially_paid');

        return [$this->money($gross), $this->money($discount), $this->money($total), $this->money($paid), $this->money($ar), $status];
    }

    private function assertReceiptConfig(Sale $sale): void
    {
        if (! $sale->cashbook_id || ! $sale->cash_category_id) {
            throw ValidationException::withMessages(['cashbook_id' => 'An active branch cashbook and cash-in category are required when paid amount is greater than zero.']);
        }
        $book = Cashbook::query()->whereKey($sale->cashbook_id)->where('branch_id', $sale->branch_id)->where('currency_code', $sale->currency_code)->where('status', 'active')->first();
        if (! $book) {
            throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook for the sale branch is required.']);
        }
        $category = CashLedgerCategory::query()->whereKey($sale->cash_category_id)->where('direction', 'in')->where('status', 'active')->first();
        if (! $category) {
            throw ValidationException::withMessages(['cash_category_id' => 'An active cash-in category is required.']);
        }
    }

    private function postReceipt(Sale $sale, User $actor): void
    {
        $this->cashbookTransactions->postForModule(['cashbook_id' => $sale->cashbook_id, 'category_id' => $sale->cash_category_id, 'business_date' => $sale->sale_date->toDateString(), 'direction' => 'in', 'amount' => $sale->paid_amount, 'description' => 'Receipt for '.$sale->sale_number, 'external_reference' => $sale->sale_number, 'idempotency_key' => 'sale-'.$sale->id, 'created_by_id' => $sale->created_by_id], 'sale_receipt', $actor);
    }

    private function assertCreditLimit(Customer $customer, int $arMinor): void
    {
        $openMinor = 0;
        $entries = CustomerReceivableEntry::query()->where('customer_id', $customer->id)->get(['amount']);
        foreach ($entries as $entry) {
            $openMinor += $this->minor($entry->amount);
        }
        $limit = $this->minor($customer->credit_limit ?? 0);
        if ($limit > 0 && $openMinor + $arMinor > $limit) {
            throw ValidationException::withMessages(['customer_id' => 'Sale would exceed the customer credit limit. Projected exposure: '.$this->money($openMinor + $arMinor).'.']);
        }
    }

    private function postStock(Sale $sale, InventoryBalance $balance, int $delta, User $actor, string $type): void
    {
        $before = (int) $balance->on_hand_quantity;
        $after = $before + $delta;
        $restricted = (int) $balance->reserved_quantity + (int) $balance->quarantined_quantity + (int) $balance->damaged_quantity + (int) $balance->expired_quantity;
        if ($after < $restricted) {
            throw ValidationException::withMessages(['quantity' => 'Sale or reversal conflicts with current stock reservations.']);
        }
        InventoryLedgerEntry::query()->create(['posting_id' => 'MOV-'.Str::uuid(), 'posting_batch_id' => 'sale-'.$sale->id, 'source_module' => 'sales', 'source_type' => $type, 'source_id' => $sale->id, 'transaction_type' => $type, 'direction' => $delta < 0 ? 'out' : 'in', 'identity_key' => $balance->identity_key, 'category' => $balance->category, 'item_type' => $balance->item_type, 'item_id' => $balance->item_id, 'branch_id' => $balance->branch_id, 'inventory_id' => $balance->inventory_id, 'farm_information_id' => $balance->farm_information_id, 'location' => $balance->location, 'stock_uom_id' => $balance->stock_uom_id, 'stock_lot_id' => $balance->stock_lot_id, 'equipment_instance_id' => $balance->equipment_instance_id, 'original_quantity' => abs($delta), 'original_uom_id' => $balance->stock_uom_id, 'conversion_factor' => 1, 'quantity_in' => max(0, $delta), 'quantity_out' => max(0, -$delta), 'balance_before' => $before, 'balance_after' => $after, 'posting_status' => 'posted', 'posted_by_id' => $actor->id, 'posted_at' => now(), 'idempotency_key' => 'sale-'.$sale->id.'-'.$type, 'metadata' => ['sale_number' => $sale->sale_number, 'paid_quantity' => $sale->quantity, 'foc_quantity' => $sale->foc_quantity, 'actor_id' => $actor->id]]);
        $balance->on_hand_quantity = $after;
        $balance->available_quantity = max(0, $after - $restricted);
        $balance->last_ledger_entry_id = InventoryLedgerEntry::query()->where('idempotency_key', 'sale-'.$sale->id.'-'.$type)->value('id');
        $balance->version++;
        $balance->save();
    }

    private function assertSaleDate(string $date, User $actor): void
    {
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['sale_date' => 'Future-dated sales are not allowed.']);
        }
        if ($date < now()->toDateString() && ! $actor->can('sales.backdate')) {
            throw ValidationException::withMessages(['sale_date' => 'Back-dated sales require the sales.backdate permission.']);
        }
    }

    private function minor(string|int|float $v): int
    {
        $s = (string) $v;
        if (! preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw ValidationException::withMessages(['amount' => 'Amounts must have at most two decimal places.']);
        }

        return ($m[1] === '-' ? -1 : 1) * (((int) $m[2] * 100) + (int) str_pad($m[3] ?? '', 2, '0'));
    }

    private function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
