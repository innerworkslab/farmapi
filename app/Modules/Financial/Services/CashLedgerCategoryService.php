<?php

namespace App\Modules\Financial\Services;

use App\Modules\Financial\Models\CashLedgerCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;

class CashLedgerCategoryService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return CashLedgerCategory::query()
            ->with('reversalCategory')
            ->when($filters['direction'] ?? null, fn ($query, string $direction) => $query->where('direction', $direction))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('direction')->orderBy('name')
            ->paginate(min(max((int) ($filters['per_page'] ?? 30), 1), 100));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $actorId): CashLedgerCategory
    {
        return DB::transaction(function () use ($data, $actorId): CashLedgerCategory {
            $this->assertNameAvailable($data['name'], $data['direction']);
            $category = CashLedgerCategory::query()->create([
                'name' => trim($data['name']),
                'normalized_name' => $this->normalizeName($data['name']),
                'direction' => $data['direction'],
                'reversal_category_id' => $data['reversal_category_id'] ?? null,
                'status' => CashLedgerCategory::STATUS_ACTIVE,
                'created_by_id' => $actorId,
            ]);
            $this->validateReversalPair($category);

            return $category->load('reversalCategory');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(CashLedgerCategory $category, array $data): CashLedgerCategory
    {
        return DB::transaction(function () use ($category, $data): CashLedgerCategory {
            $category = CashLedgerCategory::query()->lockForUpdate()->findOrFail($category->id);

            $newName = $data['name'] ?? $category->name;
            $newDirection = $data['direction'] ?? $category->direction;
            if ($this->normalizeName($newName) !== $category->normalized_name || $newDirection !== $category->direction) {
                $this->assertNameAvailable($newName, $newDirection, $category->id);
            }

            if (($category->transactions()->exists() || $category->ledgerEntries()->exists()) &&
                ((isset($data['name']) && $this->normalizeName($data['name']) !== $category->normalized_name) ||
                (isset($data['direction']) && $data['direction'] !== $category->direction))) {
                throw ValidationException::withMessages(['category' => 'A category used by a cashbook transaction or ledger entry cannot change its name or direction.']);
            }
            if (isset($data['direction']) && $data['direction'] !== $category->direction &&
                $category->reversedCategories()->where('direction', $data['direction'])->exists()) {
                throw ValidationException::withMessages(['direction' => 'Changing this direction would make an existing reversal category pair invalid.']);
            }

            if (isset($data['name'])) {
                $category->name = trim($data['name']);
                $category->normalized_name = $this->normalizeName($data['name']);
            }
            if (isset($data['direction'])) {
                $category->direction = $data['direction'];
            }
            if (array_key_exists('reversal_category_id', $data)) {
                $category->reversal_category_id = $data['reversal_category_id'];
            }
            $category->save();
            $this->validateReversalPair($category);

            return $category->refresh()->load('reversalCategory');
        });
    }

    public function setStatus(CashLedgerCategory $category, string $status): CashLedgerCategory
    {
        return DB::transaction(function () use ($category, $status): CashLedgerCategory {
            $category = CashLedgerCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($status === CashLedgerCategory::STATUS_INACTIVE && $category->transactions()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['status' => 'Resolve draft transactions using this category before deactivating it.']);
            }

            $category->status = $status;
            $category->save();

            return $category->load('reversalCategory');
        });
    }

    public function findActiveForDirection(int $id, string $direction): CashLedgerCategory
    {
        $category = CashLedgerCategory::query()->with('reversalCategory')->lockForUpdate()->findOrFail($id);
        if ($category->status !== CashLedgerCategory::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['category_id' => 'An active cash ledger category is required.']);
        }
        if ($category->direction !== $direction) {
            throw ValidationException::withMessages(['category_id' => 'The category must match the cash in/out direction.']);
        }

        return $category;
    }

    private function validateReversalPair(CashLedgerCategory $category): void
    {
        if (! $category->reversal_category_id) {
            return;
        }
        $paired = CashLedgerCategory::query()->find($category->reversal_category_id);
        if (! $paired || $paired->direction === $category->direction) {
            throw ValidationException::withMessages(['reversal_category_id' => 'The reversal category must have the opposite cash direction.']);
        }
        if ($paired->reversal_category_id && (int) $paired->reversal_category_id !== (int) $category->id) {
            throw ValidationException::withMessages(['reversal_category_id' => 'The reversal category is paired with a different category.']);
        }
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? trim($name));
    }

    private function assertNameAvailable(string $name, string $direction, ?int $ignoreId = null): void
    {
        $query = CashLedgerCategory::query()
            ->where('direction', $direction)
            ->where('normalized_name', $this->normalizeName($name));
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A category with this name and direction already exists.']);
        }
    }
}
