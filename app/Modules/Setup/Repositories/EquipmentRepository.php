<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Equipment;

class EquipmentRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Equipment::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'serial_number', 'brand', 'model', 'manufacturer'];
    }

    protected function filterColumns(): array
    {
        return ['brand' => 'brand', 'category' => 'category', 'manufacturer' => 'manufacturer', 'supplier_id' => 'supplier_id'];
    }

    protected function relations(): array
    {
        return ['supplier'];
    }
}