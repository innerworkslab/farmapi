<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Customer;

class CustomerRepository extends BusinessMasterRepository
{
    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function searchColumns(): array
    {
        return ['code', 'name', 'phone_number', 'contact_person'];
    }

    protected function filterColumns(): array
    {
        return ['payment_terms' => 'payment_terms', 'preferred_branch_id' => 'preferred_branch_id', 'price_level' => 'price_level', 'status' => 'status', 'type' => 'type'];
    }

    protected function relations(): array
    {
        return ['preferredBranch'];
    }
}