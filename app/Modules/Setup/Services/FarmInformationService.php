<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\FarmInformationRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FarmInformationService extends BusinessMasterService
{
    public function __construct(FarmInformationRepository $repository)
    {
        parent::__construct($repository);
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            app(FarmLocationService::class)->validateSelection($data);

            return $this->repository->create($data);
        });
    }

    public function update(Model $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data): Model {
            app(FarmLocationService::class)->validateSelection($data);
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);

            return $this->repository->update($model, $data);
        });
    }
}
