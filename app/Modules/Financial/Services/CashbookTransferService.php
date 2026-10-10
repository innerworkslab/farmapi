<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\CashbookTransfer;
use App\Modules\Financial\Support\Money;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashbookTransferService
{
    public function __construct(private readonly FinancialControlService $controls, private readonly CashbookTransactionService $cash) {}

    public function paginate(User $actor)
    {
        return BranchAccess::scope(CashbookTransfer::query(), $actor)->orderByDesc('id')->paginate(30);
    }

    public function create(array $data, User $actor): CashbookTransfer
    {
        return DB::transaction(function () use ($data, $actor) {
            [$source, $destination] = $this->books((int) $data['source_cashbook_id'], (int) $data['destination_cashbook_id'], $actor);
            $date = $data['business_date'] ?? $this->controls->businessDate((int) $source->branch_id);
            $amount = Money::minor($data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Transfer amount must be positive.']);
            }
            $existing = CashbookTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $matches = $existing->source_cashbook_id === $source->id && $existing->destination_cashbook_id === $destination->id
                    && (int) $existing->out_category_id === (int) $data['out_category_id'] && (int) $existing->in_category_id === (int) $data['in_category_id']
                    && Money::minor($existing->amount) === $amount && $existing->business_date->toDateString() === $date && $existing->description === trim($data['description']);
                if (! $matches) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was used for different transfer data.']);
                }

                return $existing;
            }

            return CashbookTransfer::query()->create(array_merge($data, ['branch_id' => $source->branch_id, 'currency_code' => $source->currency_code,
                'amount' => Money::decimal($amount), 'description' => trim($data['description']),
                'business_date' => $date, 'reference' => 'CTR-'.Str::upper((string) Str::ulid()), 'created_by_id' => $actor->id, 'state' => 'draft']));
        });
    }

    public function confirm(CashbookTransfer $transfer, User $actor): CashbookTransfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            [$source, $destination] = $this->books((int) $transfer->source_cashbook_id, (int) $transfer->destination_cashbook_id, $actor);
            $transfer = CashbookTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->state === 'confirmed') {
                return $transfer;
            }
            if ($transfer->state !== 'draft') {
                throw ValidationException::withMessages(['transfer' => 'Only draft transfers can be confirmed.']);
            }
            $base = ['business_date' => $transfer->business_date->toDateString(), 'amount' => $transfer->amount, 'description' => trim($transfer->description), 'created_by_id' => $transfer->created_by_id];
            $out = $this->cash->postForModule($base + ['cashbook_id' => $source->id, 'category_id' => $transfer->out_category_id, 'direction' => 'out', 'external_reference' => $transfer->reference.'-OUT', 'idempotency_key' => 'transfer-'.$transfer->id.'-out'], 'cashbook_transfer', $actor);
            $in = $this->cash->postForModule($base + ['cashbook_id' => $destination->id, 'category_id' => $transfer->in_category_id, 'direction' => 'in', 'external_reference' => $transfer->reference.'-IN', 'idempotency_key' => 'transfer-'.$transfer->id.'-in'], 'cashbook_transfer', $actor);
            $transfer->fill(['state' => 'confirmed', 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id, 'confirmed_by_id' => $actor->id, 'confirmed_at' => now()])->save();

            return $transfer->refresh();
        });
    }

    public function reverse(CashbookTransfer $transfer, string $reason, User $actor): CashbookTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actor) {
            $this->books((int) $transfer->source_cashbook_id, (int) $transfer->destination_cashbook_id, $actor);
            $transfer = CashbookTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->state === 'reversed') {
                return $transfer;
            }
            if ($transfer->state !== 'confirmed') {
                throw ValidationException::withMessages(['transfer' => 'Only confirmed transfers can be reversed.']);
            }
            $this->cash->reverseForModule(CashbookTransaction::query()->findOrFail($transfer->in_transaction_id), 'cashbook_transfer', $reason, $actor);
            $this->cash->reverseForModule(CashbookTransaction::query()->findOrFail($transfer->out_transaction_id), 'cashbook_transfer', $reason, $actor);
            $transfer->fill(['state' => 'reversed', 'reversal_reason' => trim($reason)])->save();

            return $transfer->refresh();
        });
    }

    public function cancel(CashbookTransfer $transfer, User $actor): CashbookTransfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = CashbookTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            BranchAccess::authorize($actor, (int) $transfer->branch_id);
            if ($transfer->state !== 'draft') {
                throw ValidationException::withMessages(['transfer' => 'Only draft transfers can be cancelled.']);
            }
            $transfer->state = 'cancelled';
            $transfer->save();

            return $transfer;
        });
    }

    private function books(int $sourceId, int $destinationId, User $actor): array
    {
        if ($sourceId === $destinationId) {
            throw ValidationException::withMessages(['destination_cashbook_id' => 'Choose a different cashbook.']);
        }
        $source = $this->controls->lockCashbook($sourceId, $actor);
        $destination = Cashbook::query()->findOrFail($destinationId);
        BranchAccess::authorize($actor, (int) $destination->branch_id);
        if ((int) $source->branch_id !== (int) $destination->branch_id || $source->currency_code !== $destination->currency_code) {
            throw ValidationException::withMessages(['destination_cashbook_id' => 'Transfers require two cashbooks in the same branch and currency.']);
        }
        $destination = Cashbook::query()->lockForUpdate()->findOrFail($destinationId);
        if ($source->status !== 'active' || $destination->status !== 'active') {
            throw ValidationException::withMessages(['cashbook_id' => 'Both cashbooks must be active.']);
        }

        return [$source, $destination];
    }
}
