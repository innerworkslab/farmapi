<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Supplier;

class SupplierRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'phone_number', 'contact_person'];
    }

    protected function filterColumns(): array
    {
        return ['payment_terms' => 'payment_terms', 'preferred_branch_id' => 'preferred_branch_id', 'status' => 'status', 'type' => 'type'];
    }

    protected function relations(): array
    {
        return ['preferredBranch'];
    }
}