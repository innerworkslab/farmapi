<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Setup\Models\Inventory;
use App\Modules\Setup\Repositories\InventoryRepository;
use App\Support\BranchAccess;

class InventoryService extends BusinessMasterService
{
    public function __construct(InventoryRepository $repository)
    {
        parent::__construct($repository);
    }

    public function locationOptions(array $filters, User $actor): array
    {
        $query = BranchAccess::scope(Inventory::query(), $actor);
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        return [
            'building_zones' => (clone $query)->whereNotNull('building_zone')->where('building_zone', '<>', '')->distinct()->orderBy('building_zone')->pluck('building_zone')->values(),
            'rack_bins' => (clone $query)->whereNotNull('rack_bin')->where('rack_bin', '<>', '')->distinct()->orderBy('rack_bin')->pluck('rack_bin')->values(),
        ];
    }
}
