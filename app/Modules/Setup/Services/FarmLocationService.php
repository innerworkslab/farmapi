<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Setup\Repositories\FarmLocationRepository;
use App\Support\BranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FarmLocationService
{
    public function __construct(private readonly FarmLocationRepository $records) {}

    public function paginate(string $kind, array $filters, User $actor)
    {
        return $this->records->paginate($kind, $filters, $actor);
    }

    public function find(string $kind, int $id, User $actor)
    {
        $record = $this->records->find($kind, $id);
        BranchAccess::authorize($actor, $record->branch_id);

        return $record;
    }

    public function save(string $kind, array $data, User $actor, ?int $id = null)
    {
        try {
            return DB::transaction(function () use ($kind, $data, $actor, $id) {
                $record = $id ? $this->find($kind, $id, $actor) : null;
                BranchAccess::authorize($actor, (int) $data['branch_id']);
                // Lock parent barns first, consistently with selection and deletion.
                $parents = $kind === 'pen-cage-ponds' ? array_filter([$data['house_barn_id'] ?? null, $record?->house_barn_id]) : [];
                sort($parents);
                foreach (array_unique($parents) as $parent) {
                    $this->records->find('house-barns', (int) $parent, true);
                }
                if ($kind === 'pen-cage-ponds' && ! empty($data['house_barn_id'])) {
                    $this->active('house-barns', (int) $data['house_barn_id'], (int) $data['branch_id']);
                }
                $record = $id ? $this->records->find($kind, $id, true) : null;
                if ($record) {
                    BranchAccess::authorize($actor, $record->branch_id);
                }
                if ($record && ((int) $record->branch_id !== (int) $data['branch_id']
                    || ($kind === 'pen-cage-ponds' && $record->house_barn_id != ($data['house_barn_id'] ?? null)))
                    && $this->records->isReferenced($kind, $id)) {
                    throw ValidationException::withMessages(['location' => 'A referenced location cannot be moved to another branch or barn.']);
                }
                $normalized = AnimalClassificationService::normalize($data['name']);
                Validator::make(['name' => $normalized], ['name' => ['required', Rule::unique($kind === 'house-barns' ? 'house_barns' : 'pen_cage_ponds', 'normalized_name')->where('branch_id', $data['branch_id'])->ignore($id)]])->validate();
                $payload = ['name' => trim(preg_replace('/\s+/u', ' ', $data['name'])), 'normalized_name' => $normalized,
                    'branch_id' => $data['branch_id'], 'status' => $data['status'] ?? $record?->status ?? 'active'];
                if ($kind === 'pen-cage-ponds') {
                    $payload['house_barn_id'] = $data['house_barn_id'] ?? null;
                }
                if ($record) {
                    $record->fill($payload);
                    $record->version++;
                    $record->save();
                } else {
                    $record = $this->records->query($kind)->create($payload);
                }

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This name is already reserved in this branch.']);
        }
    }

    public function toggleStatus(string $kind, int $id, User $actor)
    {
        return DB::transaction(function () use ($kind, $id, $actor) {
            $record = $this->find($kind, $id, $actor);
            if ($kind === 'pen-cage-ponds' && $record->house_barn_id) {
                $parent = $this->records->find('house-barns', $record->house_barn_id, true);
                if ($record->status === 'inactive' && $parent->status !== 'active') {
                    throw ValidationException::withMessages(['house_barn_id' => 'Activate the parent barn first.']);
                }
            }
            $record = $this->records->find($kind, $id, true);
            BranchAccess::authorize($actor, $record->branch_id);
            $record->status = $record->status === 'active' ? 'inactive' : 'active';
            $record->version++;
            $record->save();

            return $record;
        });
    }

    public function delete(string $kind, int $id, User $actor): void
    {
        DB::transaction(function () use ($kind, $id, $actor) {
            $record = $this->find($kind, $id, $actor);
            if ($kind === 'pen-cage-ponds' && $record->house_barn_id) {
                $this->records->find('house-barns', $record->house_barn_id, true);
            }
            $record = $this->records->find($kind, $id, true);
            BranchAccess::authorize($actor, $record->branch_id);
            if ($this->records->isReferenced($kind, $id)) {
                throw ValidationException::withMessages(['location' => 'This location is referenced. Deactivate it instead.']);
            }
            $record->delete();
        });
    }

    public function validateSelection(array $data): void
    {
        Validator::make($data, [
            'branch_id' => ['required', 'integer'],
            'house_barn_id' => ['nullable', 'integer', 'required_without:pen_cage_pond_id'],
            'pen_cage_pond_id' => ['nullable', 'integer', 'required_without:house_barn_id'],
        ])->validate();
        $barnId = $data['house_barn_id'] ?? null;
        if ($barnId) {
            $this->active('house-barns', (int) $barnId, (int) $data['branch_id']);
        }
        if (! empty($data['pen_cage_pond_id'])) {
            $pen = $this->active('pen-cage-ponds', (int) $data['pen_cage_pond_id'], (int) $data['branch_id']);
            if ($pen->house_barn_id !== null && (int) $pen->house_barn_id !== (int) $barnId) {
                throw ValidationException::withMessages(['pen_cage_pond_id' => 'Select a pen belonging to the selected barn.']);
            }
        }
    }

    private function active(string $kind, int $id, int $branchId)
    {
        $record = $this->records->query($kind)->whereKey($id)->lockForUpdate()->first();
        if (! $record || $record->status !== 'active' || (int) $record->branch_id !== $branchId) {
            throw ValidationException::withMessages([$kind === 'house-barns' ? 'house_barn_id' : 'pen_cage_pond_id' => 'Select an active location in the selected branch.']);
        }

        return $record;
    }
}
