<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\SupplierRepository;

class SupplierService extends BusinessMasterService
{
    public function __construct(SupplierRepository $repository)
    {
        parent::__construct($repository);
    }
}