<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\FarmInformationRepository;
use App\Modules\Setup\Models\HouseBarn;
use App\Modules\Setup\Models\PenCagePond;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmInformationService extends BusinessMasterService
{
    public function __construct(FarmInformationRepository $repository)
    {
        parent::__construct($repository);
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $barnIds = array_values(array_unique(array_map('intval', $data['house_barn_ids'] ?? [])));
            $penIds = array_values(array_unique(array_map('intval', $data['pen_cage_pond_ids'] ?? [])));
            unset($data['house_barn_ids'], $data['pen_cage_pond_ids']);
            $this->validateLocations((int) $data['branch_id'], $barnIds, $penIds);
            $farm = $this->repository->create($data);
            $this->syncLocations($farm, (int) $farm->branch_id, $barnIds, $penIds);

            return $farm->refresh()->load($this->locationRelations());
        });
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $barnIds = array_values(array_unique(array_map('intval', $data['house_barn_ids'] ?? [])));
            $penIds = array_values(array_unique(array_map('intval', $data['pen_cage_pond_ids'] ?? [])));
            unset($data['house_barn_ids'], $data['pen_cage_pond_ids']);
            $this->validateLocations((int) $data['branch_id'], $barnIds, $penIds);
            $farm = $this->repository->update($model, $data);
            $this->syncLocations($farm, (int) $farm->branch_id, $barnIds, $penIds);

            return $farm->refresh()->load($this->locationRelations());
        });
    }

    /** @param list<int> $barnIds @param list<int> $penIds */
    private function validateLocations(int $branchId, array $barnIds, array $penIds): void
    {
        foreach ([
            ['ids' => $barnIds, 'model' => HouseBarn::class, 'field' => 'house_barn_ids'],
            ['ids' => $penIds, 'model' => PenCagePond::class, 'field' => 'pen_cage_pond_ids'],
        ] as $selection) {
            if ($selection['ids'] === []) {
                continue;
            }
            $records = $selection['model']::query()->whereIn('id', $selection['ids'])->lockForUpdate()->get();
            if ($records->count() !== count($selection['ids']) || $records->contains(fn (Model $record): bool => (int) $record->branch_id !== $branchId || $record->status !== 'active')) {
                throw ValidationException::withMessages([$selection['field'] => 'Select active locations that belong to the farm branch.']);
            }
        }
    }

    /** @param list<int> $barnIds @param list<int> $penIds */
    private function syncLocations(Model $farm, int $branchId, array $barnIds, array $penIds): void
    {
        $farm->houseBarns()->sync($this->pivotRows($barnIds, $branchId));
        $farm->penCagePonds()->sync($this->pivotRows($penIds, $branchId));
    }

    /** @param list<int> $ids @return array<int, array{branch_id: int}> */
    private function pivotRows(array $ids, int $branchId): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = ['branch_id' => $branchId];
        }

        return $rows;
    }

    /** @return list<string> */
    private function locationRelations(): array
    {
        return ['houseBarns', 'penCagePonds', 'branch', 'currentAnimal', 'responsibleEmployee'];
    }
}
