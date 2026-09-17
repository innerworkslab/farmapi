<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Inventory;

class InventoryRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Inventory::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'physical_address', 'building_zone', 'rack_bin'];
    }

    protected function filterColumns(): array
    {
        return ['branch_id' => 'branch_id', 'status' => 'status', 'type' => 'type'];
    }

    protected function relations(): array
    {
        return ['branch'];
    }
}