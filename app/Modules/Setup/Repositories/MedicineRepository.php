<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Medicine;

class MedicineRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Medicine::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'generic_name', 'registration_number', 'barcode_sku'];
    }

    protected function filterColumns(): array
    {
        return ['category' => 'category', 'default_supplier_id' => 'default_supplier_id', 'status' => 'status', 'target_animal_type' => 'target_animal_type', 'type' => 'type'];
    }

    protected function relations(): array
    {
        return ['purchaseUom', 'stockUom', 'usageUom', 'defaultSupplier'];
    }
}