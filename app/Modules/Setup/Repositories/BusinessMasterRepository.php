<?php

namespace App\Modules\Setup\Repositories;

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
    public function paginate(array $filters): LengthAwarePaginator
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->with($this->relations())
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    foreach ($this->searchColumns() as $index => $column) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}($column, 'like', "%{$search}%");
                    }
                });
            })
            ->when(true, function (Builder $query) use ($filters): void {
                foreach ($this->filterColumns() as $filter => $column) {
                    if (array_key_exists($filter, $filters) && $filters[$filter] !== null && $filters[$filter] !== '') {
                        $query->where($column, $filters[$filter]);
                    }
                }
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

    private function hasFillable(Model $model, string $attribute): bool
    {
        return in_array($attribute, $model->getFillable(), true);
    }
}