<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Financial\Support\Money;
use App\Modules\Sales\Models\CustomerReceivableEntry;
use App\Modules\Setup\Models\Customer;
use App\Modules\Setup\Repositories\CustomerRepository;
use App\Support\BranchAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService extends BusinessMasterService
{
    public function __construct(CustomerRepository $repository)
    {
        parent::__construct($repository);
    }

    public function createWithOpeningBalance(array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($data, $actor): Customer {
            $branchId = $data['opening_balance_branch_id'] ?? $data['preferred_branch_id'] ?? null;
            unset($data['opening_balance_branch_id']);
            if ((float) ($data['opening_balance'] ?? 0) > 0) {
                $data['opening_balance_date'] = today();
            }
            $customer = $this->create($data);
            $amount = (float) ($data['opening_balance'] ?? 0);
            if ($amount > 0) {
                BranchAccess::authorize($actor, (int) $branchId);
                CustomerReceivableEntry::query()->create([
                    'customer_id' => $customer->id, 'sale_id' => null, 'branch_id' => $branchId,
                    'entry_type' => 'opening_balance', 'amount' => $amount,
                    'reference' => 'CUSTOMER-OPENING-'.$customer->id,
                    'idempotency_key' => 'customer-'.$customer->id.'-opening-balance',
                    'business_date' => today(), 'created_by_id' => $actor->id,
                ]);
                activity('setup')->performedOn($customer)->causedBy($actor)
                    ->withProperties(['amount' => $amount, 'branch_id' => $branchId, 'business_date' => today()->toDateString()])
                    ->log('Customer opening receivable posted');
            }

            return $customer;
        });
    }

    public function update(Model $model, array $data): Model
    {
        unset($data['opening_balance_date']);
        if ($model instanceof Customer && CustomerReceivableEntry::query()->where('customer_id', $model->id)->where('entry_type', 'opening_balance')->exists()) {
            if ((float) ($data['opening_balance'] ?? $model->opening_balance) !== (float) $model->opening_balance) {
                throw ValidationException::withMessages(['opening_balance' => 'Posted opening receivables are immutable. Record a dated receivable adjustment instead.']);
            }
        }

        return parent::update($model, $data);
    }

    public function adjustReceivable(Customer $customer, array $data, User $actor): CustomerReceivableEntry
    {
        BranchAccess::authorize($actor, (int) $data['branch_id']);
        $existing = CustomerReceivableEntry::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            if ((int) $existing->customer_id !== (int) $customer->id || (int) $existing->branch_id !== (int) $data['branch_id'] || Money::minor($existing->amount) !== Money::minor($data['amount']) || $existing->reference !== mb_substr($data['reason'], 0, 255)) {
                throw ValidationException::withMessages(['idempotency_key' => 'This key was used for a different receivable adjustment.']);
            }

            return $existing;
        }
        $entry = CustomerReceivableEntry::query()->create([
            'customer_id' => $customer->id, 'sale_id' => null, 'branch_id' => $data['branch_id'],
            'entry_type' => 'adjustment', 'amount' => $data['amount'], 'reference' => mb_substr($data['reason'], 0, 255),
            'idempotency_key' => $data['idempotency_key'], 'business_date' => today(), 'created_by_id' => $actor->id,
        ]);
        activity('setup')->performedOn($customer)->causedBy($actor)
            ->withProperties(['entry_id' => $entry->id, 'amount' => $entry->amount, 'branch_id' => $entry->branch_id, 'reason' => $data['reason']])
            ->log('Customer receivable adjusted');

        return $entry;
    }
}
