<?php

namespace App\Modules\Financial\Services;

use App\Modules\Financial\Models\AssetCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetCategoryService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return AssetCategory::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')
            ->paginate(min(max((int) ($filters['per_page'] ?? 30), 1), 100));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $actorId): AssetCategory
    {
        return DB::transaction(function () use ($data, $actorId): AssetCategory {
            $this->assertNameAvailable($data['name']);

            return AssetCategory::query()->create([
                'name' => trim($data['name']),
                'normalized_name' => $this->normalizeName($data['name']),
                'status' => AssetCategory::STATUS_ACTIVE,
                'created_by_id' => $actorId,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(AssetCategory $category, array $data): AssetCategory
    {
        return DB::transaction(function () use ($category, $data): AssetCategory {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            if (isset($data['name']) && $this->normalizeName($data['name']) !== $category->normalized_name) {
                if ($category->depreciations()->exists()) {
                    throw ValidationException::withMessages(['name' => 'An asset category used by depreciation records cannot be renamed.']);
                }
                $this->assertNameAvailable($data['name'], $category->id);
                $category->name = trim($data['name']);
                $category->normalized_name = $this->normalizeName($data['name']);
            }
            $category->save();

            return $category->refresh();
        });
    }

    public function setStatus(AssetCategory $category, string $status): AssetCategory
    {
        return DB::transaction(function () use ($category, $status): AssetCategory {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($status === AssetCategory::STATUS_INACTIVE && $category->depreciations()->whereIn('status', ['draft', 'active'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Resolve draft or active depreciations using this category before deactivating it.']);
            }

            $category->status = $status;
            $category->save();

            return $category->refresh();
        });
    }

    public function findActive(int $id): AssetCategory
    {
        $category = AssetCategory::query()->lockForUpdate()->findOrFail($id);
        if ($category->status !== AssetCategory::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['asset_category_id' => 'An active asset category is required.']);
        }

        return $category;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? trim($name));
    }

    private function assertNameAvailable(string $name, ?int $ignoreId = null): void
    {
        $query = AssetCategory::query()->where('normalized_name', $this->normalizeName($name));
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'An asset category with this name already exists.']);
        }
    }
}
