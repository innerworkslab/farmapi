<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Financial\Models\CashbookTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashbookTransactionService
{
    public function __construct(private readonly CashbookService $cashbooks) {}

    public function find(CashbookTransaction $transaction, User $actor): CashbookTransaction
    {
        $transaction = CashbookTransaction::query()->with(['cashbook', 'ledgerEntry', 'reversedByTransaction'])->findOrFail($transaction->id);
        $this->cashbooks->find($transaction->cashbook, $actor);

        return $transaction;
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, User $actor): CashbookTransaction
    {
        return DB::transaction(function () use ($data, $actor): CashbookTransaction {
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($data['cashbook_id']);
            $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
            if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook is required.']);
            }

            $payload = [
                'business_date' => $data['business_date'] ?? now()->toDateString(),
                'direction' => $data['direction'],
                'amount' => $this->formatMinor($this->toMinor($data['amount'])),
                'description' => trim($data['description']),
                'external_reference' => $this->normalizeReference($data['external_reference'] ?? null),
            ];

            if (! empty($data['idempotency_key'])) {
                $existing = CashbookTransaction::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if ((int) $existing->cashbook_id !== (int) $cashbook->id ||
                        $existing->business_date->toDateString() !== $payload['business_date'] ||
                        $existing->direction !== $payload['direction'] ||
                        $this->toMinor($existing->amount) !== $this->toMinor($payload['amount']) ||
                        $existing->description !== $payload['description'] ||
                        $existing->external_reference !== $payload['external_reference']) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for different transaction data.']);
                    }

                    return $existing->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
                }
            }

            if ($payload['external_reference'] !== null && $cashbook->transactions()->where('external_reference', $payload['external_reference'])->exists()) {
                throw ValidationException::withMessages(['external_reference' => 'This external reference has already been used in the cashbook.']);
            }

            $transaction = $cashbook->transactions()->create([
                'reference' => $this->newReference(),
                'external_reference' => $payload['external_reference'],
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'business_date' => $payload['business_date'],
                'direction' => $payload['direction'],
                'amount' => $payload['amount'],
                'description' => $payload['description'],
                'source_type' => 'manual',
                'status' => CashbookTransaction::STATUS_DRAFT,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            return $transaction->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(CashbookTransaction $transaction, array $data, User $actor): CashbookTransaction
    {
        return DB::transaction(function () use ($transaction, $data, $actor): CashbookTransaction {
            $transaction = CashbookTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($transaction->cashbook_id);
            $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
            if ($transaction->status !== CashbookTransaction::STATUS_DRAFT) {
                throw ValidationException::withMessages(['transaction' => 'Only draft transactions can be edited.']);
            }

            if (array_key_exists('external_reference', $data)) {
                $externalReference = $this->normalizeReference($data['external_reference']);
                if ($externalReference !== null && $cashbook->transactions()->where('external_reference', $externalReference)->where('id', '<>', $transaction->id)->exists()) {
                    throw ValidationException::withMessages(['external_reference' => 'This external reference has already been used in the cashbook.']);
                }
                $transaction->external_reference = $externalReference;
            }
            if (isset($data['business_date'])) {
                $transaction->business_date = $data['business_date'];
            }
            if (isset($data['direction'])) {
                $transaction->direction = $data['direction'];
            }
            if (isset($data['amount'])) {
                $transaction->amount = $this->formatMinor($this->toMinor($data['amount']));
            }
            if (isset($data['description'])) {
                $transaction->description = trim($data['description']);
            }

            $transaction->version++;
            $transaction->save();

            return $transaction->refresh()->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
        });
    }

    public function confirm(CashbookTransaction $transaction, User $actor): CashbookTransaction
    {
        return DB::transaction(function () use ($transaction, $actor): CashbookTransaction {
            $transaction = CashbookTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($transaction->cashbook_id);
            $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);

            if ($transaction->status === CashbookTransaction::STATUS_CONFIRMED) {
                return $transaction->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
            }
            if ($transaction->status !== CashbookTransaction::STATUS_DRAFT) {
                throw ValidationException::withMessages(['transaction' => 'Only draft transactions can be confirmed.']);
            }
            $this->assertPostable($transaction, $cashbook);

            $this->postLedgerEntry($cashbook, $transaction->reference, $transaction->business_date->toDateString(),
                $transaction->description, 'manual', $transaction->direction, $transaction->amount, $actor);

            $transaction->status = CashbookTransaction::STATUS_CONFIRMED;
            $transaction->confirmed_by_id = $actor->id;
            $transaction->confirmed_at = now();
            $transaction->version++;
            $transaction->save();

            return $transaction->refresh()->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
        });
    }

    public function reverse(CashbookTransaction $transaction, string $reason, User $actor): CashbookTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $actor): CashbookTransaction {
            $transaction = CashbookTransaction::query()->with('ledgerEntry')->lockForUpdate()->findOrFail($transaction->id);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($transaction->cashbook_id);
            $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);

            if ($transaction->source_type !== 'manual') {
                throw ValidationException::withMessages(['transaction' => 'Transactions owned by another module must be reversed through that module.']);
            }
            if ($transaction->status !== CashbookTransaction::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['transaction' => 'Only confirmed transactions can be reversed.']);
            }
            if ($transaction->reversedByTransaction()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'This transaction has already been reversed.']);
            }

            $reverseDirection = $transaction->direction === 'in' ? 'out' : 'in';
            $reference = $this->newReference();
            $reversal = $cashbook->transactions()->create([
                'reference' => $reference,
                'business_date' => now()->toDateString(),
                'direction' => $reverseDirection,
                'amount' => $transaction->amount,
                'description' => 'Reversal of '.$transaction->reference.': '.trim($reason),
                'source_type' => 'manual_reversal',
                'status' => CashbookTransaction::STATUS_CONFIRMED,
                'created_by_id' => $actor->id,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'reverses_transaction_id' => $transaction->id,
                'version' => 1,
            ]);

            $this->assertPostable($reversal, $cashbook);
            $this->postLedgerEntry(
                $cashbook,
                $reference,
                $reversal->business_date->toDateString(),
                $reversal->description,
                'manual_reversal',
                $reverseDirection,
                $reversal->amount,
                $actor,
                $transaction->ledgerEntry?->id
            );

            $transaction->status = CashbookTransaction::STATUS_REVERSED;
            $transaction->reversed_by_id = $actor->id;
            $transaction->reversed_at = now();
            $transaction->reversal_reason = trim($reason);
            $transaction->version++;
            $transaction->save();

            return $reversal->refresh()->load(['cashbook', 'ledgerEntry', 'reversedByTransaction']);
        });
    }

    private function assertPostable(CashbookTransaction $transaction, Cashbook $cashbook): void
    {
        if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['cashbook_id' => 'Cashbook is inactive.']);
        }
        if ($transaction->business_date->isFuture()) {
            throw ValidationException::withMessages(['business_date' => 'Future-dated transactions cannot be posted.']);
        }
        if ($transaction->business_date->lt($cashbook->effective_date)) {
            throw ValidationException::withMessages(['business_date' => 'Transaction date cannot be before the cashbook effective date.']);
        }
        if ($this->toMinor($transaction->amount) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }
        if ($transaction->direction === 'out' && $this->currentBalanceMinor($cashbook) < $this->toMinor($transaction->amount)) {
            throw ValidationException::withMessages(['amount' => 'The cashbook does not have enough balance for this payment.']);
        }
    }

    private function postLedgerEntry(
        Cashbook $cashbook,
        string $reference,
        string $date,
        string $description,
        string $source,
        string $direction,
        string $amount,
        User $actor,
        ?int $reversalOfEntryId = null
    ): CashbookLedgerEntry {
        $previousBalance = $this->currentBalanceMinor($cashbook);
        $minor = $this->toMinor($amount);
        $balance = $direction === 'in' ? $previousBalance + $minor : $previousBalance - $minor;
        if ($balance < 0) {
            throw ValidationException::withMessages(['amount' => 'The transaction would create a negative cashbook balance.']);
        }

        return $cashbook->entries()->create([
            'reference' => $reference,
            'entry_date' => $date,
            'description' => $description,
            'source_type' => $source,
            'direction' => $direction,
            'amount' => $this->formatMinor($minor),
            'running_balance' => $this->formatMinor($balance),
            'cashbook_transaction_id' => CashbookTransaction::query()->where('reference', $reference)->value('id'),
            'reversal_of_entry_id' => $reversalOfEntryId,
            'created_by_id' => $actor->id,
        ]);
    }

    private function currentBalanceMinor(Cashbook $cashbook): int
    {
        return $this->toMinor($cashbook->entries()->orderByDesc('id')->value('running_balance') ?? $cashbook->opening_balance);
    }

    private function newReference(): string
    {
        return 'CBT-'.Str::upper((string) Str::ulid());
    }

    private function normalizeReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : $reference;
    }

    private function toMinor(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a non-negative value with at most two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
