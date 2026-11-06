<?php

namespace App\Modules\Setup\Services;

use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Repositories\AnimalRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AnimalService extends BusinessMasterService
{
    public function __construct(AnimalRepository $repository, private readonly ItemRegistryService $items)
    {
        parent::__construct($repository);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        $photo = $data['photo'] ?? null;
        unset($data['photo']);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($data, $photo, &$storedPath): Model {
                app(AnimalClassificationService::class)->validateAnimalSelection($data);
                $data['code'] = 'TMP-'.Str::uuid();
                if ($photo) {
                    $storedPath = $photo->store('animals', 'public');
                    if (! $storedPath) {
                        throw new \RuntimeException('Unable to store the animal photo.');
                    }
                    $data['photo_path'] = $storedPath;
                }
                $animal = $this->repository->create($data);
                $animal->code = 'ANI-'.$animal->id;
                $animal->save();
                $this->items->syncFromMaster($animal);

                return $animal->refresh()->load('item');
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Model $model, array $data): Model
    {
        $photo = $data['photo'] ?? null;
        unset($data['photo']);
        $storedPath = null;
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($model, $data, $photo, &$storedPath, &$oldPath): Model {
                app(AnimalClassificationService::class)->validateAnimalSelection(array_replace($model->getAttributes(), $data));
                $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
                if ($photo) {
                    $oldPath = $model->photo_path;
                    $storedPath = $photo->store('animals', 'public');
                    if (! $storedPath) {
                        throw new \RuntimeException('Unable to store the animal photo.');
                    }
                    $data['photo_path'] = $storedPath;
                }
                $animal = $this->repository->update($model, $data);
                $this->items->syncFromMaster($animal);

                return $animal->refresh()->load('item');
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }

        if ($oldPath && $oldPath !== $storedPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $updated;
    }

    public function delete(Model $model): void
    {
        DB::transaction(function () use ($model): void {
            $this->items->deactivateFromMaster($model);
            $this->repository->delete($model);
        });
    }
}
