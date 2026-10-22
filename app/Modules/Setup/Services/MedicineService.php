<?php

namespace App\Modules\Setup\Services;

use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Inventory\Services\ItemUomService;
use App\Modules\Setup\Repositories\MedicineRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MedicineService extends BusinessMasterService
{
    public function __construct(MedicineRepository $repository, private readonly ItemRegistryService $items)
    {
        parent::__construct($repository);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            app(AnimalClassificationService::class)->validateOptionalAnimalType($data['animal_type_id'] ?? null);
            app(ItemUomService::class)->validateMaster($data, 'usage_uom_id');
            $medicine = $this->repository->create($data);
            $this->items->syncFromMaster($medicine);

            return $medicine->refresh()->load('item');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $selection = array_replace($model->getAttributes(), $data);
            app(AnimalClassificationService::class)->validateOptionalAnimalType($selection['animal_type_id'] ?? null);
            app(ItemUomService::class)->validateMaster($data, 'usage_uom_id', $model);
            $medicine = $this->repository->update($model, $data);
            $this->items->syncFromMaster($medicine);

            return $medicine->refresh()->load('item');
        });
    }

    public function toggleStatus(Model $model): Model
    {
        return DB::transaction(function () use ($model): Model {
            $medicine = $this->repository->toggleStatus($model);
            $this->items->syncFromMaster($medicine);

            return $medicine->refresh()->load('item');
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
