<?php

namespace App\Modules\Setup\Services;

use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Repositories\AnimalRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AnimalService extends BusinessMasterService
{
    public function __construct(AnimalRepository $repository, private readonly ItemRegistryService $items)
    {
        parent::__construct($repository);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $animal = $this->repository->create($data);
            $this->items->syncFromMaster($animal);

            return $animal->refresh()->load('item');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model {
            $animal = $this->repository->update($model, $data);
            $this->items->syncFromMaster($animal);

            return $animal->refresh()->load('item');
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