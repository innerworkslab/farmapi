<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Food;

class FoodRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Food::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'brand', 'manufacturer'];
    }

    protected function filterColumns(): array
    {
        return ['category' => 'category', 'default_supplier_id' => 'default_supplier_id', 'status' => 'status', 'target_animal_type' => 'target_animal_type'];
    }

    protected function relations(): array
    {
        return ['purchaseUom', 'stockUom', 'consumptionUom', 'defaultSupplier'];
    }
}