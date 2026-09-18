<?php

namespace App\Modules\Setup\Services;

use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Repositories\FoodRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FoodService extends BusinessMasterService
{
    public function __construct(FoodRepository $repository, private readonly ItemRegistryService $items)
    {
        parent::__construct($repository);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $food = $this->repository->create($data);
            $this->items->syncFromMaster($food);

            return $food->refresh()->load('item');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model {
            $food = $this->repository->update($model, $data);
            $this->items->syncFromMaster($food);

            return $food->refresh()->load('item');
        });
    }

    public function toggleStatus(Model $model): Model
    {
        return DB::transaction(function () use ($model): Model {
            $food = $this->repository->toggleStatus($model);
            $this->items->syncFromMaster($food);

            return $food->refresh()->load('item');
        });
    }

    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model): void {
            $this->items->deactivateFromMaster($model);
            $this->repository->delete($model);
        });
    }
}