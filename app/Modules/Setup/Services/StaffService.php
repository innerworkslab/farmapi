<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Staff;
use App\Modules\Setup\Repositories\StaffRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(private readonly StaffRepository $staff) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        return $this->staff->paginate($filters, $actor);
    }

    public function find(Staff $staff, User $actor): Staff
    {
        $staff = $this->staff->find($staff->id);
        $this->assertBranchAccess($staff->branch, $actor, false);

        return $staff;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Staff
    {
        return DB::transaction(function () use ($data, $actor): Staff {
            $branch = Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
            $this->assertBranchAccess($branch, $actor, true);

            return $this->staff->create($this->payload($data));
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Staff $staff, array $data, User $actor): Staff
    {
        return DB::transaction(function () use ($staff, $data, $actor): Staff {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->assertBranchAccess($staff->branch, $actor, false);
            $branch = Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
            $this->assertBranchAccess($branch, $actor, true);

            return $this->staff->update($staff, $this->payload($data));
        });
    }

    public function toggleStatus(Staff $staff, User $actor): Staff
    {
        return DB::transaction(function () use ($staff, $actor): Staff {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->assertBranchAccess($staff->branch, $actor, false);
            $staff->status = $staff->status === Staff::STATUS_ACTIVE ? Staff::STATUS_INACTIVE : Staff::STATUS_ACTIVE;
            $staff->version++;
            $staff->save();

            return $staff->refresh()->load('branch');
        });
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'staff_code' => trim($data['staff_code']),
            'normalized_staff_code' => mb_strtolower(trim($data['staff_code'])),
            'name' => trim($data['name']),
            'phone_number' => isset($data['phone_number']) && trim($data['phone_number']) !== '' ? trim($data['phone_number']) : null,
            'branch_id' => $data['branch_id'],
            'employment_status' => $data['employment_status'],
        ];
    }

    private function assertBranchAccess(?Branch $branch, User $actor, bool $mustBeActive): void
    {
        if (! $branch || ($mustBeActive && $branch->status !== Branch::STATUS_ACTIVE)) {
            throw ValidationException::withMessages(['branch_id' => 'An active branch is required.']);
        }
        if (! $actor->hasRole('super-admin') && ! $actor->branches()->whereKey($branch->id)->exists()) {
            abort(403, 'You are not authorized to access this branch.');
        }
    }
}
