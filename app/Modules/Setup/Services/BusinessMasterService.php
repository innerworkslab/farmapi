<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\BusinessMasterRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BusinessMasterService
{
    public function __construct(private readonly BusinessMasterRepository $repository) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        return DB::transaction(fn () => $this->repository->create($data));
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(fn () => $this->repository->update($model, $data));
    }

    public function toggleStatus(Model $model): Model
    {
        return DB::transaction(fn () => $this->repository->toggleStatus($model));
    }

    public function delete(Model $model): void
    {
        DB::transaction(fn () => $this->repository->delete($model));
    }
}