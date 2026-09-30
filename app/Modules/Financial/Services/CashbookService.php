<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use App\Modules\Setup\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashbookService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Cashbook::query()
            ->with('branch')
            ->select('cashbooks.*')
            ->selectSub(
                CashbookLedgerEntry::query()
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
                    ->whereColumn('cashbook_id', 'cashbooks.id'),
                'current_balance'
            );

        $this->scopeToActorBranches($query, $actor);
        $query->when($filters['branch_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('branch_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['currency_code'] ?? null, fn (Builder $q, string $currency) => $q->where('currency_code', strtoupper($currency)))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%");
                    if (ctype_digit($search)) {
                        $nested->orWhere('id', (int) $search);
                    }
                });
            });

        return $query->orderBy('branch_id')->orderBy('type')->orderBy('name')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function find(Cashbook $cashbook, User $actor): Cashbook
    {
        $query = Cashbook::query()->with('branch')->select('cashbooks.*')->selectSub(
            CashbookLedgerEntry::query()
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
                ->whereColumn('cashbook_id', 'cashbooks.id'),
            'current_balance'
        );
        $this->scopeToActorBranches($query, $actor);

        return $query->findOrFail($cashbook->id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Cashbook
    {
        return DB::transaction(function () use ($data, $actor): Cashbook {
            $branch = Branch::query()->where('status', Branch::STATUS_ACTIVE)->findOrFail($data['branch_id']);
            $this->assertBranchAccess($branch, $actor);
            $name = trim((string) $data['name']);
            $normalizedName = mb_strtolower($name);

            if (Cashbook::query()->where('branch_id', $branch->id)->where('type', $data['type'])->where('normalized_name', $normalizedName)->exists()) {
                throw ValidationException::withMessages(['name' => 'A cashbook with this name and type already exists in the branch.']);
            }

            $openingBalance = $this->formatMinor($this->toMinor($data['opening_balance'] ?? '0.00'));
            $cashbook = Cashbook::query()->create([
                'branch_id' => $branch->id,
                'type' => $data['type'],
                'name' => $name,
                'normalized_name' => $normalizedName,
                'bank_reference' => $data['bank_reference'] ?? null,
                'currency_code' => strtoupper($data['currency_code']),
                'opening_balance' => $openingBalance,
                'effective_date' => $data['effective_date'],
                'status' => Cashbook::STATUS_ACTIVE,
                'created_by_id' => $actor->id,
            ]);

            $cashbook->entries()->create([
                'reference' => 'CBO-'.Str::upper((string) Str::ulid()),
                'entry_date' => $cashbook->effective_date,
                'description' => 'Opening balance',
                'source_type' => 'opening_balance',
                'direction' => 'in',
                'amount' => $openingBalance,
                'running_balance' => $openingBalance,
                'created_by_id' => $actor->id,
            ]);

            return $this->find($cashbook, $actor);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Cashbook $cashbook, array $data, User $actor): Cashbook
    {
        return DB::transaction(function () use ($cashbook, $data, $actor): Cashbook {
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($cashbook->id);
            $this->assertBranchAccess($cashbook->branch, $actor);
            if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['cashbook' => 'Only active cashbooks can be edited.']);
            }

            if (isset($data['name'])) {
                $name = trim((string) $data['name']);
                $normalized = mb_strtolower($name);
                $duplicate = Cashbook::query()->where('branch_id', $cashbook->branch_id)->where('type', $cashbook->type)
                    ->where('normalized_name', $normalized)->where('id', '<>', $cashbook->id)->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => 'A cashbook with this name and type already exists in the branch.']);
                }
                $cashbook->name = $name;
                $cashbook->normalized_name = $normalized;
            }

            if (array_key_exists('bank_reference', $data)) {
                $cashbook->bank_reference = $data['bank_reference'];
            }

            $cashbook->version++;
            $cashbook->save();

            return $this->find($cashbook, $actor);
        });
    }

    public function deactivate(Cashbook $cashbook, string $reason, User $actor): Cashbook
    {
        return DB::transaction(function () use ($cashbook, $reason, $actor): Cashbook {
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($cashbook->id);
            $this->assertBranchAccess($cashbook->branch, $actor);
            if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['cashbook' => 'Cashbook is already inactive.']);
            }
            if ($cashbook->transactions()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['cashbook' => 'Resolve pending transactions before deactivating this cashbook.']);
            }
            if ($this->currentBalanceMinor($cashbook) !== 0) {
                throw ValidationException::withMessages(['cashbook' => 'Cashbook must have a zero balance before it can be deactivated.']);
            }

            $cashbook->status = Cashbook::STATUS_INACTIVE;
            $cashbook->deactivation_reason = $reason;
            $cashbook->version++;
            $cashbook->save();

            return $this->find($cashbook, $actor);
        });
    }

    /** @param array<string, mixed> $filters */
    public function ledger(Cashbook $cashbook, array $filters, User $actor): LengthAwarePaginator
    {
        $cashbook = $this->find($cashbook, $actor);

        return $cashbook->entries()
            ->with(['transaction.category', 'category'])
            ->when($filters['from_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('entry_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('entry_date', '<=', $date))
            ->when($filters['direction'] ?? null, fn (Builder $q, string $direction) => $q->where('direction', $direction))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['source_type'] ?? null, fn (Builder $q, string $source) => $q->where('source_type', $source))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 30));
    }

    /** @param array<string, mixed> $filters */
    public function transactions(array $filters, User $actor): LengthAwarePaginator
    {
        $query = \App\Modules\Financial\Models\CashbookTransaction::query()->with(['cashbook', 'category', 'ledgerEntry.category', 'reversedByTransaction']);
        $query->whereHas('cashbook', function (Builder $books) use ($actor, $filters): void {
            $this->scopeToActorBranches($books, $actor);
            if (! empty($filters['cashbook_id'])) {
                $books->whereKey($filters['cashbook_id']);
            }
            if (! empty($filters['branch_id'])) {
                $books->where('branch_id', $filters['branch_id']);
            }
        });
        $query->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['direction'] ?? null, fn (Builder $q, string $direction) => $q->where('direction', $direction))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['from_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('business_date', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('reference', 'like', "%{$search}%")
                        ->orWhere('external_reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            });

        return $query->orderByDesc('business_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function assertBranchAccess(?Branch $branch, User $actor): void
    {
        if (! $branch || $branch->trashed() || $branch->status !== Branch::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['branch_id' => 'Branch must be active.']);
        }
        if (! $actor->hasRole('super-admin') && ! $actor->branches()->whereKey($branch->id)->exists()) {
            abort(403, 'You are not authorized to access this branch.');
        }
    }

    private function scopeToActorBranches(Builder $query, User $actor): void
    {
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
    }

    private function currentBalanceMinor(Cashbook $cashbook): int
    {
        $latest = $cashbook->entries()->orderByDesc('id')->value('running_balance');

        return $this->toMinor($latest ?? $cashbook->opening_balance);
    }

    private function toMinor(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a non-negative value with at most two decimal places.']);
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    private function formatMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
