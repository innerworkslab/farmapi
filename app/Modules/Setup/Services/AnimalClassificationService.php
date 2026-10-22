<?php

namespace App\Modules\Setup\Services;

use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Setup\Repositories\AnimalClassificationRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnimalClassificationService
{
    public function __construct(private readonly AnimalClassificationRepository $records, private readonly ItemRegistryService $items) {}

    public function paginate(string $kind, array $filters)
    {
        return $this->records->paginate($kind, $filters);
    }

    public function find(string $kind, int $id): Model
    {
        $record = $this->records->find($kind, $id);

        return $kind === 'animal-types' ? $record : $record->load('animalType');
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function save(string $kind, array $data, ?int $id = null): Model
    {
        try {
            return DB::transaction(function () use ($kind, $data, $id): Model {
                $record = $id ? $this->records->find($kind, $id) : null;
                $parentId = $data['animal_type_id'] ?? null;
                if ($kind !== 'animal-types') {
                    // Serialize child edits, parent deactivation and animal selection on the type row.
                    $parentIds = array_values(array_unique(array_filter([$parentId, $record?->animal_type_id])));
                    sort($parentIds);
                    foreach ($parentIds as $parent) {
                        $this->records->find('animal-types', (int) $parent, true);
                    }
                    $this->assertActiveType((int) $parentId);
                }
                $record = $id ? $this->records->find($kind, $id, true) : null;
                if ($record && $kind !== 'animal-types' && (int) $record->animal_type_id !== (int) $parentId && $this->records->isReferenced($kind, $record)) {
                    throw ValidationException::withMessages(['animal_type_id' => 'A referenced category or breed cannot be moved to another type.']);
                }
                $class = $this->records->modelClass($kind);
                $table = (new $class)->getTable();
                $normalized = self::normalize($data['name']);
                $unique = Rule::unique($table, 'normalized_name')->ignore($id);
                if ($kind !== 'animal-types') {
                    $unique->where('animal_type_id', $parentId);
                }
                Validator::make(['name' => $normalized], ['name' => ['required', $unique]])->validate();
                $payload = ['name' => trim(preg_replace('/\s+/u', ' ', $data['name'])), 'normalized_name' => $normalized, 'status' => $data['status'] ?? $record?->status ?? 'active'];
                if ($kind !== 'animal-types') {
                    $payload['animal_type_id'] = $parentId;
                }
                $renamed = $record && $record->name !== $payload['name'];
                if ($record) {
                    $record->fill($payload);
                    $record->version++;
                    $record->save();
                } else {
                    $record = $class::create($payload);
                }
                if ($renamed && $kind === 'animal-categories') {
                    foreach ($this->records->animalsUsing($kind, $record->id)->lockForUpdate()->get() as $animal) {
                        if (! $animal->trashed()) {
                            $this->items->syncFromMaster($animal);
                        }
                    }
                }

                $result = $this->find($kind, $record->id);
                $result->wasRecentlyCreated = $id === null;

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This name is already reserved for this animal type.']);
        }
    }

    public function toggleStatus(string $kind, int $id): Model
    {
        return DB::transaction(function () use ($kind, $id): Model {
            $record = $this->records->find($kind, $id);
            if ($kind !== 'animal-types') {
                $this->records->find('animal-types', (int) $record->animal_type_id, true);
                if ($record->status === 'inactive') {
                    $this->assertActiveType((int) $record->animal_type_id);
                }
            }
            $record = $this->records->find($kind, $id, true);
            $record->status = $record->status === 'active' ? 'inactive' : 'active';
            $record->version++;
            $record->save();

            return $this->find($kind, $id);
        });
    }

    public function delete(string $kind, int $id): void
    {
        DB::transaction(function () use ($kind, $id): void {
            $record = $this->records->find($kind, $id);
            if ($kind !== 'animal-types') {
                $this->records->find('animal-types', (int) $record->animal_type_id, true);
            }
            $record = $this->records->find($kind, $id, true);
            if ($this->records->isReferenced($kind, $record)) {
                throw ValidationException::withMessages(['classification' => 'This record is referenced. Deactivate it instead of deleting it.']);
            }
            $record->delete();
        });
    }

    public function validateAnimalSelection(array $data): void
    {
        Validator::make($data, [
            'animal_type_id' => ['required', 'integer', 'min:1'],
            'animal_category_id' => ['required', 'integer', 'min:1'],
            'animal_breed_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        $type = $this->records->query('animal-types')->whereKey($data['animal_type_id'])->lockForUpdate()->first();
        if (! $type || $type->status !== 'active') {
            throw ValidationException::withMessages(['animal_type_id' => 'Select an active animal type.']);
        }
        foreach (['animal-categories' => 'animal_category_id', 'animal-breeds' => 'animal_breed_id'] as $kind => $field) {
            $selected = $this->records->query($kind)->whereKey($data[$field])->where('animal_type_id', $type->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $selected) {
                throw ValidationException::withMessages([$field => 'Select an active record belonging to the selected animal type.']);
            }
        }

    }

    public function validateOptionalAnimalType(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $type = $this->records->query('animal-types')->whereKey($id)->lockForUpdate()->first();
        if (! $type || $type->status !== 'active') {
            throw ValidationException::withMessages(['animal_type_id' => 'Select an active animal type.']);
        }
    }

    private function assertActiveType(int $id): void
    {
        if (! $this->records->query('animal-types')->whereKey($id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['animal_type_id' => 'Select an active animal type.']);
        }
    }
}
