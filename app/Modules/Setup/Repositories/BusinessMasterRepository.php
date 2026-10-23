<?php

namespace App\Modules\Setup\Repositories;

use App\Models\User;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class BusinessMasterRepository
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['code', 'name'];
    }

    /** @return array<string, string> */
    protected function filterColumns(): array
    {
        return ['status' => 'status'];
    }

    /** @return list<string> */
    protected function relations(): array
    {
        return [];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->when($actor !== null && in_array($modelClass, [Inventory::class, FarmInformation::class], true), fn (Builder $query) => BranchAccess::scope($query, $actor))
            ->with($this->relations())
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $this->applySearch($query, $search);
                });
            })
            ->when(true, function (Builder $query) use ($filters): void {
                foreach ($this->filterColumns() as $filter => $column) {
                    if (array_key_exists($filter, $filters) && $filters[$filter] !== null && $filters[$filter] !== '') {
                        $query->where($column, $filters[$filter]);
                    }
                }
                $this->applyCustomFilters($query, $filters);
            })
            ->orderBy($this->orderColumn())
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass;

        if ($this->hasFillable($model, 'status')) {
            $data += ['status' => 'active'];
        }

        return $modelClass::query()->create($data)->load($this->relations());
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        $model->fill($data);

        if ($this->hasFillable($model, 'version')) {
            $model->version++;
        }

        $model->save();

        return $model->refresh()->load($this->relations());
    }

    public function toggleStatus(Model $model): Model
    {
        $model->status = $model->status === 'active' ? 'inactive' : 'active';

        if ($this->hasFillable($model, 'version')) {
            $model->version++;
        }

        $model->save();

        return $model->refresh()->load($this->relations());
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    protected function orderColumn(): string
    {
        return 'name';
    }

    /** @param array<string, mixed> $filters */
    protected function applyCustomFilters(Builder $query, array $filters): void {}

    protected function applySearch(Builder $query, string $search): void
    {
        foreach ($this->searchColumns() as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}($column, 'like', "%{$search}%");
        }
    }

    private function hasFillable(Model $model, string $attribute): bool
    {
        return in_array($attribute, $model->getFillable(), true);
    }
}
