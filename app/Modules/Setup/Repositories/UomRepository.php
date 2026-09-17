<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Uom;

class UomRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Uom::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'symbol'];
    }

    protected function filterColumns(): array
    {
        return ['category' => 'category', 'status' => 'status'];
    }

    protected function relations(): array
    {
        return [];
    }
}