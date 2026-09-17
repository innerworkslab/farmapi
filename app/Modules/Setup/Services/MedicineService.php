<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\MedicineRepository;

class MedicineService extends BusinessMasterService
{
    public function __construct(MedicineRepository $repository)
    {
        parent::__construct($repository);
    }
}