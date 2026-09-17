<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Repositories\BranchRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(private readonly BranchRepository $branches) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->branches->paginate($filters);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Branch
    {
        return DB::transaction(fn () => $this->branches->create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(fn () => $this->branches->update($branch, $data));
    }


    public function toggleStatus(Branch $branch): Branch
    {
        return DB::transaction(fn () => $this->branches->toggleStatus($branch));
    }
    public function delete(Branch $branch): void
    {
        DB::transaction(function () use ($branch): void {
            $this->branches->delete($branch);
        });
    }
}
